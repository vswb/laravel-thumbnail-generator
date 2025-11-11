<div class="flexbox-annotated-section">

    <div class="flexbox-annotated-section-annotation">
        <div class="annotated-section-title pd-all-20">
            <h2>{{ trans('Dung lượng upload tối đa') }}</h2>
        </div>
        <div class="annotated-section-description pd-all-20 p-none-t">
            <p class="color-note">{{ trans('Cấu hình thông tin upload tối đa của website.') }}</p>
        </div>
    </div>

    <div class="flexbox-annotated-section-content">
        <div class="wrapper-content pd-all-20">
            @php 
                $allowedMimeTypes = explode(',', \AppMedia::getConfig('allowed_mime_types'));
            @endphp
            <div 
                class="form-group" 
                id="admin_filesize_wrapper" 
                data-mime-type="{{ json_encode($allowedMimeTypes) }}" 
                data-sizes="{{ json_encode(get_max_mimesizes()) }}" 
                data-max="{{ count($allowedMimeTypes) }}"
            >
                <label class="text-title-field"
                       for="admin_size">{{ trans('Cấu hình') }}</label>
                <a id="add" class="link mt-2 d-block" data-placeholder="2MB"><small>+ {{ trans('core/setting::setting.email_add_more') }}</small></a>
                {{ Form::helper(trans('Upload tối đa của từng loại file, dung lượng tính bằng megabyte (VD: 2MB, 4MB, 6MB, ...)')) }}
            </div>
        </div>
    </div>

</div>


@push('footer')
<script>
    const handleAdminSetupFileSize = () => {
        let $wrapper = $('#admin_filesize_wrapper');

        if (!$wrapper.length) {
            return;
        }

        let $addBtn  = $wrapper.find('#add');
        let max = parseInt($wrapper.data('max'), 10);

        let sizes = $wrapper.data('sizes');
        let mimeTypes = $wrapper.data('mime-type');

        if (sizes.length === 0) {
            sizes = [];
        }

        let mimeTypeSetuped = []

        const onAddSize = () => {
            let count = $wrapper.find('input[type=number]').length;

            if (count >= max) {
                $addBtn.addClass('disabled');
            } else {
                $addBtn.removeClass('disabled');
            }

            mimeTypeSetuped = []
            for (const item of $wrapper.find(".more-size > select")) {
                mimeTypeSetuped.push(item.value)
            }
        }

        const addSize = (value = null, index) => {
            const mimes = mimeTypes.filter(el => {
                return mimeTypeSetuped.findIndex(i => i == el) == -1
            })
            return $addBtn.before(`<div class="d-flex mt-2 more-size align-items-center">
                <select class="form-control rounded-0 select-search-full" style="height: 36px;text-transform: capitalize;" name="media_config_mimesize[${index}][type]" value="${value.type}">
                    ${mimes.map(el => {
                        if(el == value.type) {
                            return `<option value="${el}" selected>${el}</option>`
                        } 
                        return `<option value="${el}">${el}</option>`
                    })}    
                </select>
                <input type="text" style="border-radius: unset !important;border-left: unset; height: 36px" class="form-control" placeholder="${$addBtn.data('placeholder')}" name="media_config_mimesize[${index}][size]" value="${value.size}" />
                <div class="input-group-append">
                    <span class="input-group-text" style="border-radius: unset;height: 36px;font-size: 13px;border-left: unset;" id="basic-addon2">MB</span>
                </div>
                <a class="btn btn-link text-danger"><i class="fas fa-minus"></i></a>
            </div>`)
        }

        const render = () => {
            sizes.forEach((size, index) => {
                addSize(size, index);
            })
            onAddSize();
        }

        $wrapper.on('click', '.more-size > a', function() {
            $(this).parent('.more-size').remove();
            onAddSize();
        })

        $addBtn.on('click', e => {
            e.preventDefault();
            addSize({
                type: '',
                size: 2
            }, $wrapper.find(".more-size").length);
            onAddSize();
        })

        render();
    }
    $(document).ready(() => {
        handleAdminSetupFileSize()
    });
</script>
@endpush