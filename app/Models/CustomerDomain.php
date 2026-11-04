<?php

namespace App\Models;

use App\Models\Concerns\OnMainSchema;
use Illuminate\Database\Eloquent\Model;

/** A website's own domain and the customer it belongs to, looked up before anyone signs in. */
class CustomerDomain extends Model
{
    use OnMainSchema;

    public $timestamps = false;

    protected $fillable = ['domain', 'customer_id'];
}
