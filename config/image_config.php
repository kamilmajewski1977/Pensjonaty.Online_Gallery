<?php

return [
    'upload_root' => __DIR__ . '/../storage/property',
    'max_sizes' => 10,
    'min_dimensions' => [
        'width' => 800,
        'height' => 450,
    ],
    'naming' => [
        'directory_pattern' => 'property/{id_property}',
        'file_pattern' => 'P{id_property}_B{id_building}_R{id_room}{feature_segment}_width-{width}_height-{height}_{index}.{extension}',
        'feature_segment_format' => '_F{id_feature}',
    ],
    'sizes' => [
        [
            'label' => 'thumbnail',
            'width' => 400,
            'height' => 300,
            'crop' => true,
        ],
        [
            'label' => 'medium',
            'width' => 800,
            'height' => 600,
            'crop' => false,
        ],
        [
            'label' => 'large',
            'width' => 1600,
            'height' => 900,
            'crop' => false,
        ],
    ],
];
