<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One step of a process flow: the form, stage query or get (process_id) run after the main form saves. */
class MetaProcessWorkflow extends Model
{
    protected $table = 'meta_process_workflow';

    protected $fillable = ['main_process_id', 'process_id', 'order_number', 'pos_x', 'pos_y'];

    protected $casts = ['order_number' => 'integer', 'pos_x' => 'integer', 'pos_y' => 'integer'];

    public function process(): BelongsTo
    {
        return $this->belongsTo(Meta::class, 'process_id');
    }

    /** Where this step's values come from. */
    public function behaviours(): HasMany
    {
        return $this->hasMany(MetaProcessBehaviour::class, 'workflow_id')->orderBy('id');
    }
}
