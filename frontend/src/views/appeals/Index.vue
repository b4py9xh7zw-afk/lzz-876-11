<template>
  <div class="space-y-6">
    <div class="flex items-center justify-between">
      <h1 class="text-2xl font-bold text-gray-900">申诉中心</h1>
      <div v-if="isStaff" class="flex gap-2">
        <button
          v-for="tab in statusTabs"
          :key="tab.value"
          @click="filterStatus = tab.value; fetchAppeals()"
          class="px-3 py-1.5 text-sm rounded-full border"
          :class="filterStatus === tab.value ? 'bg-indigo-600 text-white border-indigo-600' : 'text-gray-600 border-gray-300 hover:bg-gray-50'"
        >
          {{ tab.label }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="appeals.length === 0" class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
      暂无申诉记录
    </div>

    <div v-else class="space-y-4">
      <div v-for="appeal in appeals" :key="appeal.id" class="bg-white rounded-lg shadow p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="space-y-1">
            <div class="flex items-center gap-2 flex-wrap">
              <span class="font-semibold text-gray-900">{{ eventTypeLabel(appeal.event?.event_type) }}</span>
              <span class="px-2 py-0.5 text-xs rounded-full" :class="statusBadgeClass(appeal.status)">{{ statusLabel(appeal.status) }}</span>
            </div>
            <div class="text-sm text-gray-500">
              <span v-if="isStaff">申诉人：{{ appeal.user?.real_name || appeal.user?.username }} · </span>
              试卷：{{ appeal.exam_record?.exam_paper?.title }} · 异常时间：{{ formatTime(appeal.event?.occurred_at) }}
            </div>
          </div>
          <div class="text-sm text-gray-500">
            当前生效分：<span class="font-bold" :class="Number(appeal.exam_record?.score) >= 60 ? 'text-green-600' : 'text-red-600'">{{ appeal.exam_record?.score }}</span>
            <span v-if="Number(appeal.exam_record?.penalty_score) > 0" class="text-xs text-red-500 ml-1">(已扣 {{ appeal.exam_record?.penalty_score }} 分)</span>
          </div>
        </div>

        <div class="mt-3 bg-gray-50 rounded p-3 text-sm text-gray-700">
          <span class="font-medium">申诉说明：</span>{{ appeal.reason }}
        </div>

        <div v-if="appeal.review_comment" class="mt-2 text-sm text-gray-600">
          <span class="font-medium">复核意见：</span>{{ appeal.review_comment }}
          <span v-if="appeal.reviewer" class="text-xs text-gray-400 ml-2">—— {{ appeal.reviewer.real_name || appeal.reviewer.username }}</span>
        </div>

        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button
            v-if="appeal.screenshot_path"
            @click="viewScreenshot(appeal)"
            class="text-sm text-indigo-600 border border-indigo-200 px-3 py-1.5 rounded hover:bg-indigo-50"
          >
            查看截图
          </button>
          <router-link
            :to="`/records/${appeal.exam_record_id}/proctoring`"
            class="text-sm text-gray-600 border border-gray-200 px-3 py-1.5 rounded hover:bg-gray-50"
          >
            监考回放
          </router-link>
          <template v-if="isStaff && appeal.status === 'pending'">
            <button @click="openReview(appeal, 'approved')" class="text-sm bg-green-600 text-white px-3 py-1.5 rounded hover:bg-green-700">申诉成立</button>
            <button @click="openReview(appeal, 'rejected')" class="text-sm bg-red-600 text-white px-3 py-1.5 rounded hover:bg-red-700">驳回申诉</button>
          </template>
        </div>
      </div>
    </div>

    <!-- 复核弹窗 -->
    <Teleport to="body">
      <div v-if="reviewModal.show" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
        <div class="fixed inset-0 bg-gray-600/75" @click="reviewModal.show = false"></div>
        <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg p-6 z-[81]">
          <h3 class="text-lg font-bold text-gray-900 mb-4">
            {{ reviewModal.action === 'approved' ? '申诉成立（改判）' : '驳回申诉' }}
          </h3>
          <textarea
            v-model="reviewModal.comment"
            rows="3"
            maxlength="1000"
            class="w-full border border-gray-300 rounded-md p-3 text-sm focus:ring-indigo-500 focus:border-indigo-500"
            placeholder="复核意见（可选）"
          ></textarea>
          <label v-if="reviewModal.action === 'approved'" class="mt-3 flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" v-model="reviewModal.restoreScore" class="h-4 w-4 text-indigo-600 border-gray-300 rounded" />
            恢复该记录的违规扣分（生效分回到卷面分，成绩统计同步更新）
          </label>
          <div class="mt-5 flex justify-end gap-3">
            <button @click="reviewModal.show = false" class="px-4 py-2 text-sm text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200">取消</button>
            <button
              @click="submitReview"
              :disabled="reviewModal.submitting"
              class="px-4 py-2 text-sm text-white rounded-md disabled:opacity-50"
              :class="reviewModal.action === 'approved' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'"
            >
              {{ reviewModal.submitting ? '提交中...' : '确认' }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- 截图查看弹窗 -->
    <Teleport to="body">
      <div v-if="screenshotUrl" class="fixed inset-0 z-[80] flex items-center justify-center p-4" @click="closeScreenshot">
        <div class="fixed inset-0 bg-gray-900/80"></div>
        <img :src="screenshotUrl" class="relative z-[81] max-w-full max-h-[85vh] rounded-lg shadow-2xl" />
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import api from '../../api'
import { useAuthStore } from '../../stores/auth'
import { useToast } from '../../composables/useToast'

const authStore = useAuthStore()
const { success: toastSuccess, error: toastError } = useToast()

const isStaff = computed(() => authStore.isTeacher || authStore.isAdmin)

const appeals = ref([])
const loading = ref(true)
const filterStatus = ref('')
const statusTabs = [
  { value: '', label: '全部' },
  { value: 'pending', label: '待复核' },
  { value: 'approved', label: '已成立' },
  { value: 'rejected', label: '已驳回' },
]

const reviewModal = ref({
  show: false,
  appeal: null,
  action: 'approved',
  comment: '',
  restoreScore: true,
  submitting: false,
})

const screenshotUrl = ref('')

const eventTypeLabels = {
  tab_switch: '切屏',
  camera_disconnect: '摄像头断开',
  idle_timeout: '长时间不操作',
  network_recovery: '网络恢复',
}

const fetchAppeals = async () => {
  loading.value = true
  try {
    const params = filterStatus.value ? { status: filterStatus.value } : {}
    const response = await api.get('/appeals', { params })
    appeals.value = response.data.appeals.data
  } catch (e) {
    console.error('Failed to fetch appeals:', e)
  } finally {
    loading.value = false
  }
}

onMounted(fetchAppeals)

const formatTime = (t) => (t ? new Date(t).toLocaleString() : '-')
const eventTypeLabel = (t) => eventTypeLabels[t] || t || '未知事件'
const statusLabel = (s) => ({ pending: '待复核', approved: '申诉成立', rejected: '申诉驳回' }[s] || s)
const statusBadgeClass = (s) => ({
  pending: 'bg-yellow-100 text-yellow-800',
  approved: 'bg-green-100 text-green-800',
  rejected: 'bg-gray-200 text-gray-700',
}[s] || 'bg-gray-100 text-gray-800')

const openReview = (appeal, action) => {
  reviewModal.value = {
    show: true,
    appeal,
    action,
    comment: '',
    restoreScore: true,
    submitting: false,
  }
}

const submitReview = async () => {
  const { appeal, action, comment, restoreScore } = reviewModal.value
  reviewModal.value.submitting = true
  try {
    await api.post(`/appeals/${appeal.id}/review`, {
      action,
      review_comment: comment,
      restore_score: action === 'approved' ? restoreScore : false,
    })
    toastSuccess('复核完成，相关数据已联动更新')
    reviewModal.value.show = false
    await fetchAppeals()
  } catch (e) {
    toastError(e.response?.data?.message || '复核失败')
  } finally {
    reviewModal.value.submitting = false
  }
}

const viewScreenshot = async (appeal) => {
  try {
    const response = await api.get(`/appeals/${appeal.id}/screenshot`, { responseType: 'blob' })
    screenshotUrl.value = URL.createObjectURL(response.data)
  } catch (e) {
    toastError('截图加载失败')
  }
}

const closeScreenshot = () => {
  if (screenshotUrl.value) URL.revokeObjectURL(screenshotUrl.value)
  screenshotUrl.value = ''
}
</script>
