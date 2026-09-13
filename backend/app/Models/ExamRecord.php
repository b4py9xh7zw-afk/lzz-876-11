<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ExamRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'exam_paper_id',
        'start_time',
        'end_time',
        'score',
        'original_score',
        'penalty_score',
        'status',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'exam_paper_id' => 'integer',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'score' => 'decimal:2',
        'original_score' => 'decimal:2',
        'penalty_score' => 'decimal:2',
        'status' => 'string',
    ];

    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_GRADED = 'graded';

    public const STATUSES = [
        self::STATUS_IN_PROGRESS => '进行中',
        self::STATUS_SUBMITTED => '已提交',
        self::STATUS_GRADED => '已评分',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function examPaper()
    {
        return $this->belongsTo(ExamPaper::class, 'exam_paper_id');
    }

    public function answers()
    {
        return $this->hasMany(ExamRecordAnswer::class, 'exam_record_id');
    }

    public function proctoringEvents()
    {
        return $this->hasMany(ProctoringEvent::class, 'exam_record_id');
    }

    public function appeals()
    {
        return $this->hasMany(Appeal::class, 'exam_record_id');
    }

    /**
     * 应用违规扣分并联动生效分数。
     * score 始终为生效分（original_score - penalty_score），
     * 成绩统计基于 score，改判后统计自动跟随。
     */
    public function applyPenalty(float $penalty): void
    {
        $original = (float) ($this->original_score ?? $this->score ?? 0);
        $penalty = max(0, min(round($penalty, 2), $original));

        $this->update([
            'original_score' => $original,
            'penalty_score' => $penalty,
            'score' => max(0, round($original - $penalty, 2)),
        ]);
    }
}
