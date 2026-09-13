<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamRecord;
use App\Models\ProctoringEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ProctoringController extends Controller
{
    /**
     * 上报监考异常事件（考试中批量上报，含离线补报）。
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'exam_record_id' => 'required|exists:exam_records,id',
            'events' => 'required|array|min:1|max:50',
            'events.*.event_type' => 'required|in:' . implode(',', array_keys(ProctoringEvent::TYPES)),
            'events.*.occurred_at' => 'required|date',
            'events.*.event_data' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record = ExamRecord::where('id', $request->exam_record_id)
            ->where('user_id', $request->user()->id)
            ->first();

        if (!$record) {
            return response()->json(['message' => '考试记录不存在'], 404);
        }

        $created = [];
        foreach ($request->events as $eventData) {
            $created[] = ProctoringEvent::create([
                'exam_record_id' => $record->id,
                'user_id' => $record->user_id,
                'event_type' => $eventData['event_type'],
                'event_data' => $eventData['event_data'] ?? null,
                'occurred_at' => $eventData['occurred_at'],
                'status' => ProctoringEvent::STATUS_PENDING,
            ]);
        }

        return response()->json([
            'message' => '事件已记录',
            'count' => count($created),
        ], 201);
    }

    /**
     * 监考回放：按时间轴返回某次考试的全部异常事件（学生本人或教师/管理员可看）。
     */
    public function events(Request $request, ExamRecord $record)
    {
        $user = $request->user();
        if ($record->user_id !== $user->id && !$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权查看此记录'], 403);
        }

        $events = ProctoringEvent::with([
                'appeal:id,proctoring_event_id,status,reason,review_comment,created_at',
                'reviewer:id,username,real_name',
            ])
            ->where('exam_record_id', $record->id)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get();

        $record->load(['user:id,username,real_name', 'examPaper:id,title,total_score']);

        return response()->json([
            'record' => $record,
            'events' => $events,
            'event_type_labels' => ProctoringEvent::TYPES,
        ]);
    }

    /**
     * 教师/管理员：有异常事件的考试记录列表（监考管理入口）。
     */
    public function records(Request $request)
    {
        $user = $request->user();
        if (!$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $records = ExamRecord::with(['user:id,username,real_name', 'examPaper:id,title,total_score'])
            ->withCount([
                'proctoringEvents',
                'proctoringEvents as pending_events_count' => function ($q) {
                    $q->where('status', ProctoringEvent::STATUS_PENDING);
                },
                'appeals as pending_appeals_count' => function ($q) {
                    $q->where('status', \App\Models\Appeal::STATUS_PENDING);
                },
            ])
            ->has('proctoringEvents')
            ->orderBy('id', 'desc')
            ->paginate($request->input('per_page', 15));

        return response()->json(['records' => $records]);
    }

    /**
     * 教师/管理员：复核单个异常事件（确认违规 / 排除误判），可同时调整扣分。
     */
    public function reviewEvent(Request $request, ProctoringEvent $event)
    {
        $user = $request->user();
        if (!$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:confirmed,dismissed',
            'penalty_score' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($request, $event, $user) {
            $event->update([
                'status' => $request->status,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            if ($request->filled('penalty_score')) {
                $event->examRecord->applyPenalty((float) $request->penalty_score);
            }
        });

        return response()->json([
            'message' => '复核完成',
            'event' => $event->fresh(),
            'record' => $event->examRecord->fresh(),
        ]);
    }

    /**
     * 教师/管理员：调整某次考试的违规扣分（分数、统计随之联动）。
     */
    public function updatePenalty(Request $request, ExamRecord $record)
    {
        $user = $request->user();
        if (!$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'penalty_score' => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $record->applyPenalty((float) $request->penalty_score);

        return response()->json([
            'message' => '扣分已更新',
            'record' => $record->fresh(),
        ]);
    }
}
