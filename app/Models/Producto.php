<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';

    protected $fillable = [
        'title',
        'description',
        'price',
        'stock',
        'imagen_1',
        'imagen_2',
        'imagen_3',
    ];

    protected $casts = [
        'price' => 'float',
        'stock' => 'integer',
    ];

    public function getImagesAttribute(): array
    {
        $images = array_filter([
            $this->imagen_1,
            $this->imagen_2,
            $this->imagen_3,
        ]);

        return array_values($images);
    }

    public function getThumbnailAttribute(): string
    {
        return $this->imagen_1;
    }

    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
            'stock' => (int) $this->stock,
            'images' => $this->images,
            'thumbnail' => $this->thumbnail,
        ];
    }
}
