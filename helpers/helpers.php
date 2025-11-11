<?php
/**
 * Hàm này dùng để lấy danh sách dung lượng tối đa (theo từng loại mime) cho phép upload,
 * được cấu hình thông qua cài đặt (setting 'media_config_mimesize').
 * Kết quả trả về là một collection chứa các giá trị dung lượng tối đa cho từng loại mime.
 *
 * @return \Illuminate\Support\Collection
 */

if (!function_exists('apps_get_thumbnail_generation')) {
    /**
     * @param string $image
     * @param null $size
     * @param bool $relativePath
     * @return \Illuminate\Contracts\Routing\UrlGenerator|string
     * @deprecated since 5.7
     */
    function apps_get_thumbnail_generation($image, $attrs)
    {
        $htmlAttrs = "";

        $sizes = [
            get_object_image($image, '320xauto') . " 320w",
            get_object_image($image, '480xauto') . " 480w",
            get_object_image($image, '767xauto') . " 767w",
            get_object_image($image, '960xauto') . " 960w",
            get_object_image($image, '1280xauto') . " 1280w",
            get_object_image($image, '1800xauto') . " 1800w"
        ];

        $attrs = array_merge($attrs, [
            "data-sizes" => "(max-width: 959px) 100vw, 50vw",
            "sizes" => "(max-width: 959px) 100vw, 50vw",
            "data-srcset" => implode(", ", $sizes),
            "srcset" => implode(", ", $sizes)
        ]);
        foreach ($attrs as $key => $value) {
            $htmlAttrs .= (!blank($value) ? " $key=\"$value\"" : " $key");
        }

        return "<img $htmlAttrs />";
    }
}

if (!function_exists('get_max_mimesizes')) {
    /**
     * get admin email(s)
     */
    function get_max_mimesizes()
    {
        $mimesizes = json_decode(setting('media_config_mimesize', json_encode([])), true);

        return collect(is_array($mimesizes) ? $mimesizes : [$mimesizes]);
    }
}
