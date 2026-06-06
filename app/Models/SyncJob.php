<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سجل عمليات المزامنة — للمتابعة وإعادة التشغيل عند الفشل.
 *
 * @property int    $source_store_id
 * @property int    $target_store_id
 * @property string $type            initial_migration | webhook_event
 * @property string $entity_type     category | product | null
 * @property string $status          pending | running | done | failed
 * @property int    $processed       عدد العناصر المعالجة بنجاح
 * @property int    $failed          عدد العناصر الفاشلة
 * @property array  $meta            تفاصيل/أخطاء (JSON)
 */
class SyncJob extends Model
{
    protected $guarded = [];

    protected $casts = [
        'meta' => 'array',
    ];

    // ======== Status Constants ========

    const STATUS_PENDING = 'pending';
    const STATUS_RUNNING = 'running';
    const STATUS_DONE    = 'done';
    const STATUS_FAILED  = 'failed';

    // ======== Helpers ========

    /** يبدأ تسجيل عملية مزامنة جديدة */
    public static function start(int $source, int $target, string $type, ?string $entityType = null): static
    {
        return static::create([
            'source_store_id' => $source,
            'target_store_id' => $target,
            'type'            => $type,
            'entity_type'     => $entityType,
            'status'          => self::STATUS_RUNNING,
            'processed'       => 0,
            'failed'          => 0,
            'meta'            => [],
        ]);
    }

    /** يُحدّث العداد بعد كل عنصر (renamed from increment to avoid Eloquent conflict) */
    public function tick(bool $success = true): void
    {
        if ($success) {
            $this->increment('processed');
        } else {
            $this->increment('failed');
        }
    }

    /** يُنهي العملية بنجاح */
    public function finish(): void
    {
        $this->update(['status' => self::STATUS_DONE]);
    }

    /** يُسجّل الفشل مع رسالة خطأ */
    public function fail(string $message): void
    {
        $meta = $this->meta ?? [];
        $meta['error'] = $message;
        $this->update(['status' => self::STATUS_FAILED, 'meta' => $meta]);
    }

    /** يُضيف خطأ منتج/تصنيف محدد للسجل دون إيقاف العملية كلها */
    public function logError(string $entityId, string $message): void
    {
        $meta = $this->meta ?? [];
        $meta['errors'][] = ['id' => $entityId, 'msg' => $message];
        $this->update(['meta' => $meta]);
    }
}
