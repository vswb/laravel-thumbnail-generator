<?php
/**
 * (c) Copyright 2026 VISUAL WEBER COMPANY LIMITED. All rights reserved.
 * Distributed by: VISUAL WEBER CO., LTD.
 * * [PRODUCT INFORMATION]
 * This software is a proprietary product developed by Visual Weber.
 * All rights to the software and its components are reserved under 
 * Intellectual Property laws.
 * * [TERMS OF USE]
 * Usage is permitted strictly according to the License Agreement 
 * between Visual Weber and the Client.
 * -------------------------------------------------------------------------
 * (c) Bản quyền thuộc về CÔNG TY TNHH VISUAL WEBER 2026. Bảo lưu mọi quyền.
 * Phát hành bởi: Công ty TNHH Visual Weber.
 * * [THÔNG TIN SẢN PHẨM]
 * Phần mềm này là sản phẩm độc quyền được phát triển bởi Visual Weber.
 * Mọi quyền đối với phần mềm và các thành phần cấu thành đều được bảo hộ 
 * theo luật Sở hữu trí tuệ.
 * * [ĐIỀU KHOẢN SỬ DỤNG]
 * Việc sử dụng được giới hạn nghiêm ngặt theo Hợp đồng cung cấp dịch vụ/phần mềm 
 * giữa Visual Weber và Khách hàng.
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
