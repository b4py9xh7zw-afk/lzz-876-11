<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProctoringEvent extends Model
{
    use HasFactory;

    public const TYPE_TAB_SWITCH = 'tab_switch';
    public const TYPE_CAMERA_DISCONNECT = 'camera_disconnect';
    public const TYPE_IDLE_TIMEOUT = 'idle_timeout';
    public const TYPE_NETWORK_RECOVERY = 'network_recovery';

    public const TYPES = [
        self::TYPE_TAB_SWITCH => '切屏',
        self::TYPE_CAMERA_DISCONNECT => '摄像头断开',
        self::TYPE_IDLE_TIMEOUT => '长时间不操作',
        self::TYPE_NETWORK_RECOVERY => '网络恢复',
    ];

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISMISSED = 'dismissed';

    public const STATUSES = [
        self::STATUS_PENDING => '待复核',
        self::STATUS_CONFIRMED => '确认违规',
        self::STATUS_DISMISSED => '已排除',
    ];

    protected $fillable = [
        'exam_record_id',
        'user_id',
        'event_type',
        'event_data',
        'occurred_at',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    protected $casts = [
        'exam_record_id' => 'integer',
        'user_id' => 'integer',
        'event_data' => 'array',
        'occurred_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'status' => 'string',
    ];

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

    public function appeal()
    {
        // 同一事件可能有多条申诉（驳回后可再申诉），取最新一条
        return $this->hasOne(Appeal::class, 'proctoring_event_id')->latest();
    }
}
