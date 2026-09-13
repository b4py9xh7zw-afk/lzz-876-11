<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appeal;
use App\Models\ProctoringEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class AppealController extends Controller
{
    /**
     * 学生：针对某个异常事件提交申诉（说明 + 可选截图）。
     */
    public function store(Request $request, ProctoringEvent $event)
    {
        if ($event->user_id !== $request->user()->id) {
            return response()->json(['message' => '只能申诉自己的异常事件'], 403);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
            'screenshot' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existing = Appeal::where('proctoring_event_id', $event->id)
            ->where('status', Appeal::STATUS_PENDING)
            ->exists();

        if ($existing) {
            return response()->json(['message' => '该事件已有待复核的申诉，请耐心等待'], 422);
        }

        $screenshotPath = null;
        if ($request->hasFile('screenshot')) {
            $screenshotPath = $request->file('screenshot')->store('appeals', 'public');
        }

        $appeal = Appeal::create([
            'proctoring_event_id' => $event->id,
            'exam_record_id' => $event->exam_record_id,
            'user_id' => $request->user()->id,
            'reason' => $request->reason,
            'screenshot_path' => $screenshotPath,
            'status' => Appeal::STATUS_PENDING,
        ]);

        return response()->json([
            'message' => '申诉已提交，等待教师复核',
            'appeal' => $appeal,
        ], 201);
    }

    /**
     * 申诉列表：学生看自己的；教师/管理员看全部（可按状态过滤）。
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $query = Appeal::with([
            'event:id,exam_record_id,event_type,occurred_at,status',
            'examRecord:id,exam_paper_id,user_id,score,original_score,penalty_score',
            'examRecord.examPaper:id,title',
            'user:id,username,real_name',
            'reviewer:id,username,real_name',
        ])->orderBy('id', 'desc');

        if (!$user->isTeacher() && !$user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        return response()->json([
            'appeals' => $query->paginate($request->input('per_page', 15)),
        ]);
    }

    /**
     * 教师/管理员：复核申诉。
     * 改判（approved）时联动：异常标记置为已排除；可选恢复该记录扣分，
     * 分数与成绩统计随之更新。
     */
    public function review(Request $request, Appeal $appeal)
    {
        $user = $request->user();
        if (!$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权访问'], 403);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|in:approved,rejected',
            'review_comment' => 'nullable|string|max:1000',
            'restore_score' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        if ($appeal->status !== Appeal::STATUS_PENDING) {
            return response()->json(['message' => '该申诉已复核，请勿重复操作'], 422);
        }

        $approved = $request->action === Appeal::STATUS_APPROVED;

        DB::transaction(function () use ($request, $appeal, $user, $approved) {
            $appeal->update([
                'status' => $approved ? Appeal::STATUS_APPROVED : Appeal::STATUS_REJECTED,
                'review_comment' => $request->review_comment,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            // 联动异常标记：申诉成立 -> 事件排除；申诉驳回 -> 确认违规
            $appeal->event->update([
                'status' => $approved ? ProctoringEvent::STATUS_DISMISSED : ProctoringEvent::STATUS_CONFIRMED,
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);

            // 申诉成立且选择恢复分数：清除违规扣分，生效分回到卷面分
            if ($approved && $request->boolean('restore_score')) {
                $record = $appeal->examRecord;
                if ($record && (float) $record->penalty_score > 0) {
                    $record->applyPenalty(0);
                }
            }
        });

        return response()->json([
            'message' => '复核完成',
            'appeal' => $appeal->fresh()->load([
                'event:id,exam_record_id,event_type,occurred_at,status',
                'examRecord:id,exam_paper_id,user_id,score,original_score,penalty_score',
                'examRecord.examPaper:id,title',
                'user:id,username,real_name',
                'reviewer:id,username,real_name',
            ]),
        ]);
    }

    /**
     * 查看申诉截图（带鉴权，避免暴露存储目录）。
     */
    public function screenshot(Request $request, Appeal $appeal)
    {
        $user = $request->user();
        if ($appeal->user_id !== $user->id && !$user->isTeacher() && !$user->isAdmin()) {
            return response()->json(['message' => '无权查看该截图'], 403);
        }

        if (!$appeal->screenshot_path || !Storage::disk('public')->exists($appeal->screenshot_path)) {
            return response()->json(['message' => '截图不存在'], 404);
        }

        return Storage::disk('public')->response($appeal->screenshot_path);
    }
}
