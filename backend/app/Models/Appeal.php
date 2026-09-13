<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appeal extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING => '待复核',
        self::STATUS_APPROVED => '申诉成立',
        self::STATUS_REJECTED => '申诉驳回',
    ];

    protected $fillable = [
        'proctoring_event_id',
        'exam_record_id',
        'user_id',
        'reason',
        'screenshot_path',
        'status',
        'review_comment',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'proctoring_event_id' => 'integer',
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
        'status' => 'string',
    ];

    public function event()
    {
        return $this->belongsTo(ProctoringEvent::class, 'proctoring_event_id');
    }

    public function examRecord()
    {
        return $this->belongsTo(ExamRecord::class, 'exam_record_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
