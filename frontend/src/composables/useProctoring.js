import { ref } from 'vue'
import api from '../api'

const IDLE_LIMIT_SECONDS = 60
const QUEUE_KEY = 'proctoring_event_queue'
const FLUSH_BATCH = 20

/**
 * 考试监考采集器：
 *  - 切屏（visibilitychange）
 *  - 摄像头断开（getUserMedia track ended + 轮询兜底）
 *  - 长时间不操作（60 秒无鼠标/键盘事件）
 *  - 网络恢复（online 事件）
 * 事件先入本地队列（localStorage 持久化），在线时批量上报；
 * 网络断开期间的事件会缓存，恢复后自动补报。
 */
export function useProctoring() {
  const cameraActive = ref(false)
  const cameraError = ref('')
  const videoEl = ref(null)
  const eventCount = ref(0)

  let recordId = null
  let stream = null
  let queue = []
  let stopped = true
  let flushing = false
  let idleTimer = null
  let cameraCheckTimer = null
  let flushTimer = null

  const loadQueue = () => {
    try {
      queue = JSON.parse(localStorage.getItem(QUEUE_KEY) || '[]')
    } catch (e) {
      queue = []
    }
  }

  const saveQueue = () => {
    try {
      localStorage.setItem(QUEUE_KEY, JSON.stringify(queue.slice(-200)))
    } catch (e) {
      // 存储满时静默失败，避免影响考试
    }
  }

  const flush = async () => {
    if (flushing || stopped || !recordId || queue.length === 0) return
    if (navigator.onLine === false) return
    flushing = true
    const batch = queue.splice(0, FLUSH_BATCH)
    try {
      await api.post('/proctoring/events', {
        exam_record_id: recordId,
        events: batch,
      })
      saveQueue()
    } catch (e) {
      // 上报失败：放回队列头部，等待下次补报
      queue.unshift(...batch)
      saveQueue()
    } finally {
      flushing = false
    }
  }

  const pushEvent = (type, data = {}) => {
    if (stopped) return
    queue.push({
      event_type: type,
      occurred_at: new Date().toISOString(),
      event_data: data,
    })
    eventCount.value++
    saveQueue()
    flush()
  }

  // ---- 切屏 ----
  const onVisibilityChange = () => {
    if (document.hidden) {
      pushEvent('tab_switch')
    }
  }

  // ---- 长时间不操作 ----
  const resetIdleTimer = () => {
    if (idleTimer) clearTimeout(idleTimer)
    idleTimer = setTimeout(() => {
      pushEvent('idle_timeout', { idle_seconds: IDLE_LIMIT_SECONDS })
      resetIdleTimer()
    }, IDLE_LIMIT_SECONDS * 1000)
  }

  const onUserActivity = () => {
    resetIdleTimer()
  }

  // ---- 网络恢复 ----
  const onOnline = () => {
    pushEvent('network_recovery')
    flush()
  }

  // ---- 摄像头 ----
  const markCameraDisconnected = (reason) => {
    if (!cameraActive.value) return
    cameraActive.value = false
    pushEvent('camera_disconnect', { reason })
  }

  const watchStream = () => {
    if (!stream) return
    stream.getVideoTracks().forEach((track) => {
      track.addEventListener('ended', () => markCameraDisconnected('track_ended'))
    })
  }

  const checkCamera = () => {
    if (!stream) return
    const tracks = stream.getVideoTracks()
    const alive = tracks.length > 0 && tracks.some((t) => t.readyState === 'live' && t.enabled)
    if (!alive) {
      markCameraDisconnected('track_not_live')
    }
  }

  const startCamera = async () => {
    if (!navigator.mediaDevices?.getUserMedia) {
      cameraError.value = '当前浏览器不支持摄像头'
      pushEvent('camera_disconnect', { reason: 'unsupported' })
      return
    }
    try {
      stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false })
      cameraActive.value = true
      cameraError.value = ''
      if (videoEl.value) {
        videoEl.value.srcObject = stream
      }
      watchStream()
    } catch (e) {
      cameraActive.value = false
      cameraError.value = '摄像头不可用'
      pushEvent('camera_disconnect', { reason: 'init_failed' })
    }
  }

  const stopCamera = () => {
    if (stream) {
      stream.getTracks().forEach((t) => t.stop())
      stream = null
    }
    cameraActive.value = false
  }

  const start = (examRecordId) => {
    recordId = examRecordId
    stopped = false
    loadQueue()

    document.addEventListener('visibilitychange', onVisibilityChange)
    window.addEventListener('online', onOnline)
    window.addEventListener('mousemove', onUserActivity, { passive: true })
    window.addEventListener('keydown', onUserActivity, { passive: true })
    window.addEventListener('click', onUserActivity, { passive: true })

    resetIdleTimer()
    startCamera()
    cameraCheckTimer = setInterval(checkCamera, 5000)
    flushTimer = setInterval(flush, 10000)
    flush() // 补报历史缓存事件
  }

  const stop = async () => {
    if (stopped) return
    stopped = true

    document.removeEventListener('visibilitychange', onVisibilityChange)
    window.removeEventListener('online', onOnline)
    window.removeEventListener('mousemove', onUserActivity)
    window.removeEventListener('keydown', onUserActivity)
    window.removeEventListener('click', onUserActivity)

    if (idleTimer) clearTimeout(idleTimer)
    if (cameraCheckTimer) clearInterval(cameraCheckTimer)
    if (flushTimer) clearInterval(flushTimer)
    stopCamera()

    // 停止前尽力把剩余事件报出去
    if (recordId && queue.length > 0 && navigator.onLine !== false) {
      const batch = queue.splice(0, FLUSH_BATCH * 2)
      try {
        await api.post('/proctoring/events', {
          exam_record_id: recordId,
          events: batch,
        })
        saveQueue()
      } catch (e) {
        queue.unshift(...batch)
        saveQueue()
      }
    }
  }

  return {
    cameraActive,
    cameraError,
    videoEl,
    eventCount,
    start,
    stop,
    flush,
  }
}
