# Thumbnail Generator

## Branches

- 📦 lte.6x-is_dev: sử dụng cho các phiên bản CMS <=6.x và namespace hệ thống dưới dạng `Dev\\`
- 📦 lte.6x-is_platform: sử dụng cho các phiên bản CMS <=6.x và namespace hệ thống dưới dạng `Platform\\`
- 📦 v7x: sử dụng cho các phiên bản CMS >=7.x và namespace hệ thống lúc này luôn luôn dưới dạng `Dev\\`

Package tự động tạo và tối ưu thumbnail images với hỗ trợ WebP cho Laravel CMS.

## 📋 Mục lục

- [Cài đặt](#cài-đặt)
- [Tính năng](#tính-năng)
- [Facade (ThumbnailMedia)](#facade-thumbnailmedia)
- [Route Resize](#route-resize)
- [WebP Optimization](#webp-optimization)
- [Cache](#cache)
- [Sử dụng](#sử-dụng)
- [Tùy chỉnh](#tùy-chỉnh)
- [Troubleshooting](#troubleshooting)

---

## 🚀 Cài đặt

1. Copy package vào thư mục: `dev-extensions/libs/thumbnail-generator`
2. Đảm bảo package đã được autoload trong `composer.json`
3. Service provider tự động được đăng ký: `Dev\ThumbnailGenerator\Providers\ThumbnailGeneratorServiceProvider`

---

## ✨ Tính năng

- ✅ **Tự động resize** với query params `?w={width}&h={height}`
- ✅ **Force WebP output** cho tất cả format (jpg, jpeg, png, gif, webp) - tối ưu Google PageSpeed
- ✅ **WebP source priority** - tự động dùng file `.webp` nếu có (từ AppMedia conversion)
- ✅ **Cache thông minh** - cache trên disk với ETag và Last-Modified headers
- ✅ **Auto cleanup** - tự động xóa thumbnails khi xóa file trong CMS
- ✅ **Tích hợp AppMedia** - sử dụng tất cả tính năng mới (WebP conversion, auto resize, validation, events)

---

## 🎯 Facade (ThumbnailMedia)

Facade đã được tự động đăng ký và bind vào service container. Không cần cấu hình thêm.

### Sử dụng trong Blade (không cần import)

```blade
{!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}
```

### Sử dụng trong PHP Class

```php
use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade as ThumbnailMedia;

$imageUrl = ThumbnailMedia::getImageUrl('storage/news/image.jpg', '300x200');
```

---

## 🔄 Route Resize

### URL Pattern

```
/resize/{slug}?w={width}&h={height}
```

### Parameters

- **{slug}**: Đường dẫn file ảnh từ thư mục public (ví dụ: `storage/news/image.jpg`)
- **w**: Chiều rộng (pixels, optional - tự động tính nếu không có)
- **h**: Chiều cao (pixels, optional - tự động tính nếu không có)

### Features

- ✅ Hỗ trợ format: `jpg`, `jpeg`, `png`, `webp`, `gif`
- ✅ Tự động giới hạn max-width: `1800px`
- ✅ Tự động tính tỉ lệ aspect ratio
- ✅ **Force output WebP** cho tất cả format (tối ưu performance)

### Ví dụ

```
/resize/storage/news/image.jpg?w=300&h=200
/resize/storage/uploads/photo.png?w=500
/resize/storage/gallery/image.jpg?h=400
```

---

## 🎨 WebP Optimization

Package tự động **force output WebP** cho tất cả image formats để tối ưu Google PageSpeed Insights.

### Logic hoạt động

1. **Request**: `/resize/storage/news/image.jpg?w=300&h=200`
2. **Check WebP source**: Tự động tìm file `storage/news/image.webp` (nếu có từ AppMedia conversion)
3. **Nếu WebP source TỒN TẠI**:
   - ✅ Dùng file WebP làm nguồn (tốt hơn)
   - ✅ Encode lại thành WebP với quality 85
   - ✅ Set `Content-Type: image/webp`
4. **Nếu WebP source KHÔNG TỒN TẠI**:
   - ✅ Dùng file gốc (jpg/png/jpeg)
   - ✅ Encode thành WebP với quality 85
   - ✅ Set `Content-Type: image/webp`
5. **Auto cleanup**: Tự động xóa file cache cũ (jpg/jpeg/png) khi tạo WebP mới

### Điều kiện

- ✅ **Force WebP** cho: `jpg`, `jpeg`, `png`, `gif`, `webp`
- ✅ **WebP source priority**: Nếu có file `.webp` cùng tên, dùng làm nguồn
- ✅ **Content-Type đúng**: Tự động detect extension thực tế và set Content-Type tương ứng

### Ví dụ

```
Request: /resize/storage/news/image.jpg?w=300&h=200

Files:
  ✅ storage/news/image.jpg (tồn tại)
  ✅ storage/news/image.webp (tồn tại - từ AppMedia conversion)
  
Result: 
  - Dùng image.webp làm nguồn
  - Encode WebP quality 85
  - Content-Type: image/webp
  - Cache: public/resize/300x200/storage/news/image-{hash}.webp
```

---

## 💾 Cache

### Cache Strategy

- **Disk cache**: Thumbnail được lưu tại `public/resize/{width}x{height}/{subPath}/{normalized}-{hash}.webp`
- **In-memory meta cache**: Kích thước ảnh gốc được cache 30 ngày để giảm `getimagesize()`
- **Auto Invalidate**: Tự động clear khi file gốc thay đổi (dựa trên `filemtime`)
- **Concurrency-safe**: Sử dụng file lock để tránh generate cùng lúc
- **Auto cleanup**: Tự động xóa file cache cũ khi tạo WebP mới

### Cache Headers

```
Cache-Control: public, max-age=31536000, immutable
ETag: "{hash}"
Last-Modified: {timestamp}
```

Headers này giúp tối ưu Google PageSpeed Insights và browser caching.

### Auto Delete Thumbnails

Khi xóa file trong CMS:
- ✅ Tự động xóa thumbnails trong storage (từ AppMedia)
- ✅ Tự động xóa thumbnails trong `public/resize/` (từ ThumbnailGenerator)
- ✅ Tự động cleanup empty directories

---

## 🎨 Sử dụng

### Trong Blade Template

```blade
<!-- Cơ bản -->
{!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}

<!-- Với HTML tag -->
<img src="{!! ThumbnailMedia::getImageUrl($fileUrl, '500x300') !!}" 
     alt="Thumbnail" 
     loading="lazy">

<!-- Responsive images -->
<img src="{!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}"
     srcset="{!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!} 300w,
             {!! ThumbnailMedia::getImageUrl($fileUrl, '600x400') !!} 600w,
             {!! ThumbnailMedia::getImageUrl($fileUrl, '900x600') !!} 900w"
     sizes="(max-width: 600px) 300px,
            (max-width: 900px) 600px,
            900px"
     alt="Responsive Image">
```

### Size Options

- `'300x200'`: Kích thước cố định
- `'300xauto'`: Width cố định, height tự động tính
- `'autox200'`: Height cố định, width tự động tính

### Trong PHP Controller

```php
use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade as ThumbnailMedia;

class ArticleController extends Controller
{
    public function index()
    {
        $imageUrl = ThumbnailMedia::getImageUrl('storage/news/image.jpg', '300x200');
        return view('articles.index', compact('imageUrl'));
    }
}
```

---

## ⚙️ Tùy chỉnh

### WebP Quality

Mặc định: **85**

Thay đổi trong `PublicController.php`:

```php
$encodedImage = $image->encode(new WebpEncoder(quality: 85));
```

### Max Width Limit

Mặc định: **1800px**

Thay đổi trong `PublicController.php`:

```php
if($size[0] > 1800) {
    $size[0] = 1800;
}
```

### Cache Duration

Mặc định: **30 ngày**

Thay đổi trong `PublicController.php`:

```php
apps_cache_store($metaCacheKey, $size, 60 * 60 * 24 * 30, 'thumbnail_meta');
```

### AppMedia Settings

Package tích hợp với AppMedia, sử dụng các settings từ admin panel:
- `media_convert_image_to_webp`: Tự động convert upload thành WebP
- `media_reduce_large_image_size`: Tự động resize hình lớn
- `media_image_max_width`: Max width cho resize
- `media_image_max_height`: Max height cho resize

---

## ⚠️ Lưu ý

### File Path

- ✅ File ảnh phải nằm trong thư mục `public` hoặc có symbolic link
- ✅ Đường dẫn phải bắt đầu từ `storage/` hoặc relative path từ public

### WebP Optimization

- ✅ **Force WebP** cho tất cả format (jpg, jpeg, png, gif, webp)
- ✅ Tự động ưu tiên file `.webp` nếu có (từ AppMedia conversion)
- ✅ Tự động xóa file cache cũ khi tạo WebP mới

### Cache

- ✅ Cache sẽ tự động clear khi file gốc thay đổi (dựa trên `filemtime`)
- ✅ Cache key bao gồm cả format để tránh conflict
- ✅ Cache riêng biệt cho từng kích thước
- ✅ Tự động cleanup khi xóa file trong CMS

### ThumbnailMedia Integration

- ✅ `ThumbnailMedia` extends `AppMedia` và sử dụng tất cả tính năng mới
- ✅ `handleUpload()` gọi `parent::handleUpload()` để sử dụng WebP conversion, auto resize, validation từ AppMedia
- ✅ `deleteThumbnails()` override để xóa cả thumbnails trong `public/resize/`

---

## 🔧 Troubleshooting

### Lỗi: "Route not found"

**Nguyên nhân:** Route chưa được register

**Giải pháp:** Đảm bảo service provider đã được load và routes được register

### Lỗi: "File not found"

**Nguyên nhân:** File không tồn tại trong public path

**Giải pháp:** 
- Kiểm tra file có tồn tại: `public_path('storage/news/image.jpg')`
- Kiểm tra symbolic link: `php artisan storage:link`

### Response trả về Content-Type sai

**Nguyên nhân:** File cache cũ (jpg) đã tồn tại

**Giải pháp:** 
- Xóa cache cũ: `rm -rf public/resize/*`
- Request lại để tạo cache WebP mới

### WebP không được serve

**Kiểm tra:**
1. File WebP có được tạo trong `public/resize/`?
2. Content-Type header có đúng `image/webp`?
3. File cache cũ (jpg) đã bị xóa chưa?

---

## 📄 License

This package is part of Laravel CMS project.

---

## 👥 Support

For issues and questions, please contact: toan@visualweber.com

---

**Version:** 1.1.0  
**Last Updated:** 2025
