<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Automobile extends Model
{
    use HasFactory;

    /**
     * @var string
     */
    protected $table = 'automobiles';

    /**
     * @var string[]
     */
    protected $fillable = [
        'url_hash',
        'url',
        'brand_id',
        'name',
        'body_type',
        'segment',
        'infotainment',
        'description',
        'press_release',
        'photos',
    ];

    /**
     * @var string[]
     */
    protected $casts = [
        'photos' => 'array',
    ];

    /**
     * @return HasMany
     */
    public function engines(): HasMany
    {
        return $this->hasMany('App\Models\Engine');
    }

    /**
     * @return array
     */
    public function toCsv(): array
    {
        return [
            'id' => $this->id,
            'url_hash' => $this->url_hash,
            'url' => $this->url,
            'brand_id' => $this->brand_id,
            'name' => $this->name,
            'body_type' => $this->body_type,
            'segment' => $this->segment,
            'infotainment' => $this->infotainment,
            'description' => $this->description,
            'press_release' => $this->press_release,
            'photos' => json_encode($this->photos),
        ];
    }

}
