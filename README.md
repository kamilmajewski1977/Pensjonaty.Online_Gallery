# Pensjonaty.Online Gallery Toolkit

This repository provides backend and frontend building blocks for handling rich image galleries within the Pensjonaty.Online platform.

## PHP ImageUploader

The `Pensjonaty\Gallery\ImageUploader` class manages multi-file uploads, automatic renaming, resizing to multiple target sizes, and basic progress reporting.

```php
use Pensjonaty\Gallery\ImageUploader;

$config = require __DIR__ . '/config/image_config.php';
$uploader = new ImageUploader($config);

$context = [
    'id_property' => 10,
    'id_building' => 5,
    'id_room' => 2,
    'id_feature' => 7, // optional
    'type' => 'room',
    'placement' => 'main',
];

$files = $_FILES['images'] ?? [];
$meta = $_POST['meta'] ?? [];

$results = $uploader->processUploads($files, $context, function ($processed, $total, $result) {
    // Update UI or log upload progress here.
});

foreach ($results as $index => $result) {
    $imageMeta = $meta[$index] ?? [];
    // Persist $imageMeta['alt'], ['title'], ['description'] alongside $result['generated_files'].
}
```

Each uploaded file is renamed following the pattern `property/{id_property}/P{id_property}_B{id_building}_R{id_room}_width-{width}_height-{height}_{index}.jpg` (feature segments are appended automatically when provided). The upload directory, naming format, and available target sizes are configurable through `config/image_config.php`.

If a source image is smaller than the configured minimum dimensions (default 800×450), the uploader records a notice in the response payload without blocking the upload.

## Frontend drag & drop uploader

The jQuery plugin in `public/js/image-uploader.js` offers a drag-and-drop experience with Cropper.js-based cropping and metadata inputs (description, alt, title) per image. Include jQuery and Cropper.js, then initialise the plugin on the drop zone element:

```html
<link rel="stylesheet" href="https://unpkg.com/cropperjs@1.6.1/dist/cropper.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://unpkg.com/cropperjs@1.6.1/dist/cropper.min.js"></script>
<script src="/js/image-uploader.js"></script>
<script>
    window.poImageSizes = [
        { width: 400, height: 300 },
        { width: 800, height: 600 }
    ];
    $(function () {
        $('#po-drop-zone').attr('data-po-uploader', '1');
    });
</script>
```

```html
<div id="po-drop-zone" data-po-uploader>
    <div id="po-preview-list"></div>
    <button id="po-upload-button" type="button">Upload images</button>
</div>
```

The plugin maintains a local queue, supports concurrent uploads, tracks progress via `<progress>` elements, and posts the crop coordinates alongside metadata.

### Backend integration notes

* The uploader sends each file as `images[]` by default so PHP receives the familiar `$_FILES['images']` structure. You can change the field name via the `fileFieldName` option when initialising the widget.
* Per-image metadata (description, alt, title) is grouped into the `meta` array indexed in the same order as `$_FILES['images']`. The sample above shows how to combine that metadata with the processed upload results.
* A `_client_id` property is also included so the backend can correlate a server response with the original DOM element if desired.

## Database schema

Create the `po_images` table with the SQL found in `sql/create_po_image_table.sql`. The schema stores the relationships between properties, buildings, rooms, and optional feature identifiers while allowing custom placement/type taxonomy and metadata JSON for future-proofing.
