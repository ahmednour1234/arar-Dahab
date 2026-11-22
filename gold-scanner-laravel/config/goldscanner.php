<?php

/*
| Gold Scanner configuration. Secrets come from .env; the column mapping lives here
| so the client's real Excel layout can be mapped without code changes.
*/

return [
    'access_key' => env('GS_ACCESS_KEY', ''),
    'admin_key' => env('GS_ADMIN_KEY', ''),
    'demo_mode' => (bool) env('GS_DEMO_MODE', false),
    'upload_enabled' => (bool) env('GS_UPLOAD_ENABLED', true),
    'max_upload_mb' => (float) env('GS_MAX_UPLOAD_MB', 20),
    'backups_keep' => (int) env('GS_BACKUPS_KEEP', 10),

    // Comma-separated client IP ranges allowed (CIDR). Empty = allow all (public server).
    'allowed_networks' => array_values(array_filter(array_map('trim', explode(',', (string) env('GS_ALLOWED_NETWORKS', ''))))),

    // Storage folder (inside storage/app/private) holding inventory file, index and backups.
    'storage_dir' => 'inventory',

    // [] = first sheet only; otherwise list of sheet names.
    'sheets' => array_values(array_filter(array_map('trim', explode(',', (string) env('GS_SHEETS', ''))))),
    'header_row' => (int) env('GS_HEADER_ROW', 1),
    'include_unmapped_columns' => true,
    'case_insensitive_barcodes' => true,

    'scanner_suffix' => env('GS_SCANNER_SUFFIX', 'enter'), // enter | tab | none
    'scanner_idle_submit_ms' => 250,
    'poll_interval_seconds' => 5,

    // field => accepted header names (case-insensitive, Arabic or English)
    'columns' => [
        'barcode' => ['Barcode', 'Bar Code', 'الباركود', 'باركود', 'رقم الباركود', 'كود الباركود', 'SKU', 'رقم القطعة', 'الكود', 'Code'],
        'item_name' => ['Item Name', 'Item', 'Description', 'Name', 'اسم القطعة', 'البيان', 'الوصف', 'اسم الصنف', 'الاسم', 'الصنف'],
        'category' => ['Category', 'Type', 'Group', 'الفئة', 'التصنيف', 'النوع', 'المجموعة'],
        'karat' => ['Karat', 'Carat', 'Purity', 'العيار', 'عيار'],
        'weight' => ['Weight', 'الوزن', 'وزن'],
        'making_charge' => ['Making Charge', 'Making', 'المصنعية', 'مصنعية', 'الاجره', 'الأجرة'],
        'price' => ['Price', 'السعر', 'سعر', 'القيمة'],
        'status' => ['Status', 'الحالة'],
        'branch' => ['Branch', 'الفرع', 'المعرض'],
        'notes' => ['Notes', 'Note', 'ملاحظات', 'ملاحظة'],
    ],

    // Display order + Arabic labels
    'fields' => [
        'barcode' => 'الباركود',
        'item_name' => 'اسم القطعة',
        'category' => 'الفئة',
        'karat' => 'العيار',
        'weight' => 'الوزن (جرام)',
        'making_charge' => 'المصنعية',
        'price' => 'السعر المخزّن (ريال)',
        'status' => 'الحالة',
        'branch' => 'الفرع',
        'notes' => 'ملاحظات',
    ],
];
