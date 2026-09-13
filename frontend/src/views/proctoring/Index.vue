<template>
  <div class="space-y-6">
    <h1 class="text-2xl font-bold text-gray-900">监考管理</h1>

    <div v-if="loading" class="text-center py-12">
      <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-indigo-600 mx-auto"></div>
    </div>
    <div v-else-if="records.length === 0" class="bg-white rounded-lg shadow p-12 text-center text-gray-500">
      暂无异常监考记录
    </div>
    <div v-else class="bg-white shadow overflow-hidden sm:rounded-lg">
      <table class="min-w-full divide-y divide-gray-200">
        <thead class="bg-gray-50">
          <tr>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">考生</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">试卷</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">异常事件</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待复核</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">待处理申诉</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">生效分</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">考试时间</th>
            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase">操作</th>
          </tr>
        </thead>
        <tbody class="bg-white divide-y divide-gray-200">
          <tr v-for="record in records" :key="record.id" class="hover:bg-gray-50">
            <td class="px-6 py-4 whitespace-nowrap">{{ record.user?.real_name || record.user?.username }}</td>
            <td class="px-6 py-4 whitespace-nowrap">{{ record.exam_paper?.title }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="px-2 py-1 text-xs font-semibold rounded-full bg-red-100 text-red-800">{{ record.proctoring_events_count }} 条</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span v-if="record.pending_events_count > 0" class="px-2 py-1 text-xs font-semibold rounded-full bg-yellow-100 text-yellow-800">{{ record.pending_events_count }}</span>
              <span v-else class="text-xs text-gray-400">无</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span v-if="record.pending_appeals_count > 0" class="px-2 py-1 text-xs font-semibold rounded-full bg-blue-100 text-blue-800">{{ record.pending_appeals_count }}</span>
              <span v-else class="text-xs text-gray-400">无</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap">
              <span class="font-bold" :class="Number(record.score) >= 60 ? 'text-green-600' : 'text-red-600'">{{ record.score }}</span>
              <span v-if="Number(record.penalty_score) > 0" class="text-xs text-red-500 ml-1">(-{{ record.penalty_score }})</span>
            </td>
            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatTime(record.start_time) }}</td>
            <td class="px-6 py-4 whitespace-nowrap">
              <router-link :to="`/records/${record.id}/proctoring`" class="text-indigo-600 hover:text-indigo-900 text-sm font-medium">监考回放</router-link>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../api'

const records = ref([])
const loading = ref(true)

onMounted(async () => {
  try {
    const response = await api.get('/proctoring/records')
    records.value = response.data.records.data
  } catch (e) {
    console.error('Failed to fetch proctoring records:', e)
  } finally {
    loading.value = false
  }
})

const formatTime = (t) => (t ? new Date(t).toLocaleString() : '-')
</script>
