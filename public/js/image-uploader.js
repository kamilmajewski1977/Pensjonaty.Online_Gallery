(function ($) {
    'use strict';

    const uploader = {
        init(options) {
            this.settings = $.extend({
                dropZone: '#po-drop-zone',
                previewList: '#po-preview-list',
                uploadButton: '#po-upload-button',
                maxParallel: 3,
                sizes: [],
                uploadUrl: '/upload.php',
                fileFieldName: 'images[]',
                metaFieldName: 'meta',
                texts: {
                    dropHere: 'Drop images here or click to browse',
                    crop: 'Crop',
                    saveCrop: 'Save crop',
                    remove: 'Remove',
                    description: 'Description',
                    alt: 'Alt text',
                    title: 'Title'
                }
            }, options);

            this.cacheDom();
            this.bindEvents();
        },

        cacheDom() {
            this.$dropZone = $(this.settings.dropZone);
            this.$previewList = $(this.settings.previewList);
            this.$uploadButton = $(this.settings.uploadButton);
            this.$fileInput = this.$fileInput || $('<input type="file" accept="image/*" multiple style="display:none">');

            if (!$.contains(document.documentElement, this.$fileInput[0])) {
                $('body').append(this.$fileInput);
            }

            if (!this.$dropZone.find('.po-drop-message').length) {
                this.$dropZone.prepend('<div class="po-drop-message">' + this.settings.texts.dropHere + '</div>');
            }

            if (!this.$previewList.length) {
                this.$previewList = $('<div class="po-preview-list" id="po-preview-list"></div>');
                this.$dropZone.append(this.$previewList);
            }

            if (!this.$uploadButton.length) {
                this.$uploadButton = $('<button type="button" class="po-upload-button" id="po-upload-button">Upload</button>');
                this.$dropZone.append(this.$uploadButton);
            }
        },

        bindEvents() {
            const self = this;

            this.$fileInput.on('change', function (event) {
                self.handleFiles(event.target.files);
                $(this).val('');
            });

            this.$dropZone.on('click', function (event) {
                if ($(event.target).closest('input, textarea, button, .po-preview-actions, .po-preview-inputs').length) {
                    return;
                }

                self.$fileInput.trigger('click');
            });

            this.$dropZone.on('dragover', function (event) {
                event.preventDefault();
                event.originalEvent.dataTransfer.dropEffect = 'copy';
                $(this).addClass('is-dragover');
            });

            this.$dropZone.on('dragleave dragend drop', function () {
                $(this).removeClass('is-dragover');
            });

            this.$dropZone.on('drop', function (event) {
                event.preventDefault();
                const files = event.originalEvent.dataTransfer.files;
                self.handleFiles(files);
            });

            this.$uploadButton.on('click', function () {
                self.uploadAll();
            });
        },

        handleFiles(fileList) {
            Array.from(fileList).forEach(file => this.createPreview(file));
        },

        createPreview(file) {
            const id = 'po-preview-' + Math.random().toString(36).substr(2, 9);
            const $item = $('<div class="po-preview-item" data-id="' + id + '"></div>');
            const $imageWrapper = $('<div class="po-preview-image"></div>');
            const $image = $('<img>');
            const $progress = $('<progress max="100" value="0"></progress>');
            const $inputs = $(
                '<div class="po-preview-inputs">' +
                    '<label>' + this.settings.texts.description + '<textarea name="description"></textarea></label>' +
                    '<label>' + this.settings.texts.alt + '<input type="text" name="alt"></label>' +
                    '<label>' + this.settings.texts.title + '<input type="text" name="title"></label>' +
                '</div>'
            );
            const $buttons = $(
                '<div class="po-preview-actions">' +
                    '<button type="button" class="po-crop">' + this.settings.texts.crop + '</button>' +
                    '<button type="button" class="po-remove">' + this.settings.texts.remove + '</button>' +
                '</div>'
            );

            $imageWrapper.append($image);
            $item.append($imageWrapper, $inputs, $buttons, $progress);
            this.$previewList.append($item);

            const reader = new FileReader();
            reader.onload = event => {
                $image.attr('src', event.target.result);
                this.prepareCropper($item, $image, file);
            };
            reader.readAsDataURL(file);

            $buttons.find('.po-remove').on('click', () => {
                const objectUrl = $image.data('objectUrl');
                if (objectUrl) {
                    URL.revokeObjectURL(objectUrl);
                }
                $item.remove();
            });
        },

        prepareCropper($item, $image, file) {
            const aspectRatios = this.settings.sizes.map(size => size.width / size.height);
            const primaryRatio = aspectRatios[0] || 16 / 9;

            let cropper = null;
            const initCropper = () => {
                if (cropper) {
                    cropper.destroy();
                }
                cropper = new Cropper($image[0], {
                    aspectRatio: primaryRatio,
                    viewMode: 2,
                    autoCropArea: 1,
                    responsive: true,
                    dragMode: 'move',
                });
            };

            $item.find('.po-crop').on('click', () => {
                if (!cropper) {
                    initCropper();
                    $item.addClass('is-cropping');
                    $item.append('<button type="button" class="po-save-crop">' + this.settings.texts.saveCrop + '</button>');
                    $item.find('.po-save-crop').on('click', () => {
                        this.storeCropData($item, cropper, file).then(() => {
                            $item.removeClass('is-cropping');
                            $item.find('.po-save-crop').remove();
                            cropper.destroy();
                            cropper = null;
                        });
                    });
                }
            });

            $image.data('file', file);
        },

        storeCropData($item, cropper, originalFile) {
            const cropData = cropper.getData(true);
            const canvas = cropper.getCroppedCanvas();

            return new Promise(resolve => {
                if (!canvas) {
                    $item.data('crop', Object.assign({ originalName: originalFile.name }, cropData));
                    resolve();
                    return;
                }

                canvas.toBlob(blob => {
                    if (!blob) {
                        $item.data('crop', Object.assign({ originalName: originalFile.name }, cropData));
                        resolve();
                        return;
                    }

                    const croppedFile = new File([blob], originalFile.name, {
                        type: originalFile.type || blob.type || 'image/jpeg',
                        lastModified: Date.now()
                    });

                    const $image = $item.find('img');
                    const previousUrl = $image.data('objectUrl');
                    if (previousUrl) {
                        URL.revokeObjectURL(previousUrl);
                    }

                    const objectUrl = URL.createObjectURL(blob);
                    $image.attr('src', objectUrl);
                    $image.data('objectUrl', objectUrl);
                    $image.data('file', croppedFile);

                    $item.data('crop', Object.assign({
                        originalName: originalFile.name,
                        outputWidth: canvas.width,
                        outputHeight: canvas.height
                    }, cropData));

                    resolve();
                }, originalFile.type || 'image/jpeg');
            });
        },

        collectItems() {
            return this.$previewList.find('.po-preview-item').map(function () {
                const $this = $(this);
                return {
                    id: $this.data('id'),
                    file: $this.find('img').data('file'),
                    description: $this.find('textarea[name="description"]').val(),
                    alt: $this.find('input[name="alt"]').val(),
                    title: $this.find('input[name="title"]').val(),
                    crop: $this.data('crop') || null,
                    element: this
                };
            }).get();
        },

        uploadAll() {
            const items = this.collectItems();
            items.forEach((item, index) => {
                item.index = index;
            });
            const queue = items.slice();
            let active = 0;

            const next = () => {
                if (!queue.length) {
                    return;
                }
                while (active < this.settings.maxParallel && queue.length) {
                    this.uploadItem(queue.shift(), next);
                    active++;
                }
            };

            const onComplete = () => {
                active--;
                next();
            };

            this.uploadItem = (item, callback) => {
                const formData = new FormData();
                formData.append(this.settings.fileFieldName, item.file, item.file.name);

                if (this.settings.metaFieldName) {
                    const base = `${this.settings.metaFieldName}[${item.index}]`;
                    formData.append(`${base}[description]`, item.description);
                    formData.append(`${base}[alt]`, item.alt);
                    formData.append(`${base}[title]`, item.title);
                    formData.append(`${base}[_client_id]`, item.id);
                } else {
                    formData.append('description', item.description);
                    formData.append('alt', item.alt);
                    formData.append('title', item.title);
                }
                if (item.crop) {
                    Object.keys(item.crop).forEach(key => {
                        formData.append('crop[' + key + ']', item.crop[key]);
                    });
                }

                $.ajax({
                    url: this.settings.uploadUrl,
                    method: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    xhr: function () {
                        const xhr = $.ajaxSettings.xhr();
                        if (xhr.upload) {
                            xhr.upload.addEventListener('progress', function (event) {
                                if (event.lengthComputable) {
                                    const value = Math.round((event.loaded / event.total) * 100);
                                    $(item.element).find('progress').val(value);
                                }
                            });
                        }
                        return xhr;
                    }
                }).done(response => {
                    $(item.element).addClass('is-uploaded');
                    $(item.element).data('server-response', response);
                }).fail(() => {
                    $(item.element).addClass('is-error');
                }).always(() => {
                    callback();
                    onComplete();
                });
            };

            next();
        }
    };

    $.fn.poImageUploader = function (options) {
        options = options || {};
        options.dropZone = this;
        uploader.init(options);
        return this;
    };

    $(function () {
        if ($('[data-po-uploader]').length) {
            $('[data-po-uploader]').poImageUploader({
                sizes: window.poImageSizes || []
            });
        }
    });
})(jQuery);
