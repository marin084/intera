<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

class Tag extends Model
{
    use BelongsToWorkspace, HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'name',
        'slug',
        'color',
    ];

    protected static function booted(): void
    {
        static::creating(function (Tag $tag) {
            if (empty($tag->slug) && ! empty($tag->name)) {
                $tag->slug = Str::slug($tag->name);
            }
            if (empty($tag->color)) {
                $tag->color = '#3B82F6';
            }
        });

        static::updating(function (Tag $tag) {
            if ($tag->isDirty('name') && ! $tag->isDirty('slug')) {
                $tag->slug = Str::slug($tag->name);
            }
        });
    }

    public function conversations(): BelongsToMany
    {
        return $this->belongsToMany(Conversation::class, 'conversation_tag')
            ->withPivot('assigned_by')
            ->withTimestamps();
    }

    /**
     * Ensure standard default tags exist in the system.
     */
    public static function ensureDefaultTags(): void
    {
        $defaults = [
            ['name' => 'Petición de Oración', 'color' => '#8B5CF6'],
            ['name' => 'Promesa de Ofrenda', 'color' => '#F59E0B'],
            ['name' => 'Depósito por Validar', 'color' => '#10B981'],
            ['name' => 'Consulta General', 'color' => '#3B82F6'],
            ['name' => 'Urgente', 'color' => '#EF4444'],
        ];

        foreach ($defaults as $item) {
            self::firstOrCreate(
                ['name' => $item['name']],
                [
                    'slug' => Str::slug($item['name']),
                    'color' => $item['color'],
                ]
            );
        }
    }
}
