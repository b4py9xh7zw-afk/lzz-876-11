<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">监考回放</h1>
      <button @click="$router.back()" class="text-sm text-indigo-600 hover:text-indigo-800">← 返回</button>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>

    <template v-else-if="record">
      <!-- 记录概要 -->
      <div class="bg-white rounded-lg shadow p-6">
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
          <div>
            <div class="text-xs text-gray-500 mb-1">试卷</div>
            <div class="font-semibold text-gray-900">{{ record.exam_paper?.title }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-1">考生</div>
            <div class="font-semibold text-gray-900">{{ record.user?.real_name || record.user?.username }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-1">考试时间</div>
            <div class="font-semibold text-gray-900 text-sm">{{ formatTime(record.start_time) }}</div>
          </div>
          <div>
            <div class="text-xs text-gray-500 mb-1">异常事件</div>
            <div class="font-semibold" :class="events.length > 0 ? 'text-red-600' : 'text-green-600'">{{ events.length }} 条</div>
          </div>
        </div>
        <div class="mt-4 pt-4 border-t border-gray-100 flex flex-wrap items-center gap-6">
          <div class="text-sm">卷面分：<span class="font-bold">{{ displayOriginalScore }}</span></div>
          <div class="text-sm">违规扣分：<span class="font-bold" :class="penalty > 0 ? 'text-red-600' : 'text-gray-700'">-{{ penalty }}</span></div>
          <div class="text-sm">生效分：<span class="font-bold text-lg" :class="Number(record.score) >= 60 ? 'text-green-600' : 'text-red-600'">{{ record.score }}</span></div>
        </div>
      </div>

      <!-- 教师：扣分调整 -->
      <div v-if="isStaff" class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-900 mb-3">违规扣分调整</h3>
        <div class="flex flex-wrap items-center gap-3">
          <input
            v-model.number="penaltyInput"
            type="number"
            min="0"
            :max="displayOriginalScore"
            step="0.5"
            class="border border-gray-300 rounded-md px-3 py-2 w-32 focus:ring-indigo-500 focus:border-indigo-500"
          />
          <button @click="savePenalty" :disabled="savingPenalty" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 disabled:opacity-50 text-sm">
            {{ savingPenalty ? '保存中...' : '保存扣分' }}
          </button>
          <span class="text-xs text-gray-500">保存后生效分 = 卷面分 - 扣分，成绩统计将同步更新</span>
        </div>
      </div>

      <!-- 时间轴 -->
      <div class="bg-white rounded-lg shadow p-6">
        <h3 class="font-bold text-gray-900 mb-6">异常事件时间轴</h3>
        <div v-if="events.length === 0" class="text-center py-8 text-gray-500">
          本次考试无异常事件
        </div>
        <div v-else class="relative">
          <div class="absolute left-5 top-0 bottom-0 w-0.5 bg-gray-200"></div>
          <div class="space-y-6">
            <div v-for="event in events" :key="event.id" class="relative pl-14">
              <!-- 时间轴节点 -->
              <div class="absolute left-2.5 top-1 w-5 h-5 rounded-full border-2 flex items-center justify-center" :class="nodeClass(event)">
                <span class="w-2 h-2 rounded-full bg-current"></span>
              </div>
              <div class="border rounded-lg p-4" :class="cardClass(event)">
                <div class="flex flex-wrap items-center justify-between gap-2">
                  <div class="flex items-center gap-2">
                    <span class="font-semibold text-gray-900">{{ typeLabel(event.event_type) }}</span>
                    <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadgeClass(event.status)">{{ statusLabel(event.status) }}</span>
                    <span v-if="event.appeal" class="px-2 py-0.5 text-xs rounded-full" :class="appealBadgeClass(event.appeal.status)">
                      申诉{{ appealStatusLabel(event.appeal.status) }}
                    </span>
                  </div>
                  <span class="text-sm text-gray-500 font-mono">{{ formatTime(event.occurred_at) }}</span>
                </div>

                <div v-if="event.event_data && Object.keys(event.event_data).length" class="mt-2 text-xs text-gray-500">
                  <span v-for="(v, k) in event.event_data" :key="k" class="inline-block bg-gray-100 rounded px-2 py-0.5 mr-2">{{ k }}: {{ v }}</span>
                </div>

                <div v-if="event.appeal" class="mt-3 bg-gray-50 rounded p-3 text-sm">
                  <div class="text-gray-700"><span class="font-medium">申诉说明：</span>{{ event.appeal.reason }}</div>
                  <div v-if="event.appeal.review_comment" class="text-gray-500 mt-1"><span class="font-medium">复核意见：</span>{{ event.appeal.review_comment }}</div>
                </div>

                <div class="mt-3 flex flex-wrap gap-2">
                  <!-- 学生：申诉入口 -->
                  <button
                    v-if="!isStaff && event.status === 'pending' && !event.appeal"
                    @click="openAppeal(event)"
                    class="text-sm bg-yellow-50 text-yellow-700 border border-yellow-200 px-3 py-1.5 rounded hover:bg-yellow-100"
                  >
                    提交申诉
                  </button>
                  <!-- 教师：复核入口 -->
                  <template v-if="isStaff && event.status === 'pending'">
                    <button @click="reviewEvent(event, 'confirmed')" class="text-sm bg-red-50 text-red-700 border border-red-200 px-3 py-1.5 rounded hover:bg-red-100">确认违规</button>
                    <button @click="reviewEvent(event, 'dismissed')" class="text-sm bg-green-50 text-green-700 border border-green-200 px-3 py-1.5 rounded hover:bg-green-100">排除误判</button>
                  </template>
                  <span v-if="event.reviewer" class="text-xs text-gray-400 self-center">复核人：{{ event.reviewer.real_name || event.reviewer.username }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>

    <!-- 申诉弹窗 -->
    <Teleport to="body">
      <div v-if="appealModal.show" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-600/75" @click="appealModal.show = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 z-[81]">
          <h3 class="text-lg font-bold text-gray-900 mb-1">提交申诉</h3>
          <p class="text-sm text-gray-500 mb-4">针对「{{ typeLabel(appealModal.event?.event_type) }}」· {{ formatTime(appealModal.event?.occurred_at) }}</p>
          <textarea
            v-model="appealModal.reason"
            rows="4"
            maxlength="1000"
            class="w-full border border-gray-300 rounded-md p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500"
            placeholder="请说明当时的情况，例如：切屏是因为系统弹窗、网络中断后自动恢复等"
          ></textarea>
          <div class="mt-3">
            <label class="block text-sm text-gray-600 mb-1">上传截图（可选，≤5MB）</label>
            <input type="file" accept="image/*" @change="onScreenshotChange" class="text-sm text-gray-600" />
            <div v-if="appealModal.preview" class="mt-2">
              <img :src="appealModal.preview" class="max-h-40 rounded border" />
            </div>
          </div>
          <div class="mt-5 flex justify-end gap-3">
            <button @click="appealModal.show = false" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200">取消</button>
            <button @click="submitAppeal" :disabled="appealModal.submitting" class="px-4 py-2 text-sm text-white bg-indigo-600 rounded-md hover:bg-indigo-700 disabled:opacity-50">
              {{ appealModal.submitting ? '提交中...' : '提交申诉' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '../../api'
import { useAuthStore } from '../../stores/auth'
import { useModal } from '../../composables/useModal'
import { useToast } from '../../composables/useToast'

const route = useRoute()
const authStore = useAuthStore()
const { alert } = useModal()
const { success: toastSuccess, error: toastError } = useToast()

const isStaff = computed(() => authStore.isTeacher || authStore.isAdmin)

const loading = ref(true)
const record = ref(null)
const events = ref([])
const typeLabels = ref({})
const penaltyInput = ref(0)
const savingPenalty = ref(false)

const appealModal = ref({
  show: false,
  event: null,
  reason: '',
  file: null,
  preview: '',
  submitting: false,
})

const penalty = computed(() => Number(record.value?.penalty_score || 0))
const displayOriginalScore = computed(() => {
  const r = record.value
  if (!r) return 0
  return r.original_score ?? r.score ?? 0
})

const fetchData = async () => {
  try {
    const response = await api.get(`/proctoring/records/${route.params.id}/events`)
    record.value = response.data.record
    events.value = response.data.events
    typeLabels.value = response.data.event_type_labels || {}
    penaltyInput.value = Number(record.value.penalty_score || 0)
  } catch (e) {
    alert(e.response?.data?.message || '加载失败', '错误', 'error')
  } finally {
    loading.value = false
  }
}

onMounted(fetchData)

const formatTime = (t) => (t ? new Date(t).toLocaleString() : '-')
const typeLabel = (t) => typeLabels.value[t] || t
const statusLabel = (s) => ({ pending: '待复核', confirmed: '确认违规', dismissed: '已排除' }[s] || s)
const appealStatusLabel = (s) => ({ pending: '待复核', approved: '成立', rejected: '驳回' }[s] || s)

const nodeClass = (event) => {
  if (event.status === 'confirmed') return 'border-red-400 text-red-500 bg-red-50'
  if (event.status === 'dismissed') return 'border-green-400 text-green-500 bg-green-50'
  return 'border-yellow-400 text-yellow-500 bg-yellow-50'
}
const cardClass = (event) => {
  if (event.status === 'confirmed') return 'border-red-200 bg-red-50/40'
  if (event.status === 'dismissed') return 'border-green-200 bg-green-50/40'
  return 'border-yellow-200 bg-yellow-50/30'
}
const statusBadgeClass = (s) => ({
  pending: 'bg-yellow-100 text-yellow-800',
  confirmed: 'bg-red-100 text-red-800',
  dismissed: 'bg-green-100 text-green-800',
}[s] || 'bg-gray-100 text-gray-800')
const appealBadgeClass = (s) => ({
  pending: 'bg-blue-100 text-blue-800',
  approved: 'bg-green-100 text-green-800',
  rejected: 'bg-gray-200 text-gray-700',
}[s] || 'bg-gray-100 text-gray-800')

// ---- 学生申诉 ----
const openAppeal = (event) => {
  appealModal.value = { show: true, event, reason: '', file: null, preview: '', submitting: false }
}

const onScreenshotChange = (e) => {
  const file = e.target.files[0]
  if (!file) return
  if (file.size > 5 * 1024 * 1024) {
    toastError('截图不能超过 5MB')
    e.target.value = ''
    return
  }
  appealModal.value.file = file
  appealModal.value.preview = URL.createObjectURL(file)
}

const submitAppeal = async () => {
  if (!appealModal.value.reason.trim()) {
    toastError('请填写申诉说明')
    return
  }
  appealModal.value.submitting = true
  try {
    const formData = new FormData()
    formData.append('reason', appealModal.value.reason)
    if (appealModal.value.file) {
      formData.append('screenshot', appealModal.value.file)
    }
    await api.post(`/appeals/events/${appealModal.value.event.id}`, formData)
    toastSuccess('申诉已提交，等待教师复核')
    appealModal.value.show = false
    await fetchData()
  } catch (e) {
    toastError(e.response?.data?.message || '申诉提交失败')
  } finally {
    appealModal.value.submitting = false
  }
}

// ---- 教师复核 ----
const reviewEvent = async (event, status) => {
  try {
    await api.post(`/proctoring/events/${event.id}/review`, { status })
    toastSuccess('复核完成')
    await fetchData()
  } catch (e) {
    toastError(e.response?.data?.message || '操作失败')
  }
}

const savePenalty = async () => {
  savingPenalty.value = true
  try {
    const response = await api.post(`/proctoring/records/${record.value.id}/penalty`, {
      penalty_score: penaltyInput.value,
    })
    record.value = { ...record.value, ...response.data.record }
    toastSuccess('扣分已更新，成绩统计已同步')
  } catch (e) {
    toastError(e.response?.data?.message || '保存失败')
  } finally {
    savingPenalty.value = false
  }
}
</script>
