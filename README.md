# Thumbnail Generator

Package tự động tạo và tối ưu thumbnail images với hỗ trợ WebP cho Laravel CMS.

## 🚀 Cài đặt

1. Copy package vào: `dev-extensions/packages/thumbnail-generator`
2. Service provider tự động được đăng ký
3. Không cần cấu hình thêm, sử dụng ngay

## 📖 Cách sử dụng

### Trong Blade Template

```blade
{!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}
```

**Kích thước:**
- `'300x200'` - Kích thước cố định
- `'300xauto'` - Tự động tính height
- `'autox200'` - Tự động tính width

### Trong PHP Class/Controller

```php
use Platform\ThumbnailGenerator\Facades\ThumbnailMediaFacade as ThumbnailMedia;

$imageUrl = ThumbnailMedia::getImageUrl('storage/news/image.jpg', '300x200');
```

### URL trực tiếp

```
/resize/storage/news/image.jpg?w=300&h=200
```

## ✨ Tính năng chính

### 🎨 WebP Auto-Optimization

Tự động dùng file WebP nếu có để tối ưu tốc độ:

```
Request: /resize/storage/news/image.jpg?w=300&h=200

Nếu tồn tại: storage/news/image.webp
→ Tự động dùng WebP (quality: 85)
→ Content-Type: image/webp
```

**Điều kiện:**
- Chỉ áp dụng cho: `jpg`, `jpeg`, `png`
- File WebP phải cùng tên, cùng thư mục

### 💾 Smart Cache

- **Duration:** 30 ngày
- **Auto-invalidate:** Tự động clear khi file thay đổi
- **Cache riêng biệt** cho WebP và format gốc

### 🔧 Image Processing

- Hỗ trợ: `jpg`, `jpeg`, `png`, `webp`, `gif`
- Max-width: `1800px`
- Tự động tính tỉ lệ aspect ratio

## 📝 Ví dụ thực tế

### Resize cơ bản

```blade
<img src="{!! ThumbnailMedia::getImageUrl('storage/news/article.jpg', '500x300') !!}" 
     alt="Article" 
     loading="lazy">
```

### Responsive Images

```blade
<img src="{!! ThumbnailMedia::getImageUrl($image, '300x200') !!}"
     srcset="{!! ThumbnailMedia::getImageUrl($image, '300x200') !!} 300w,
             {!! ThumbnailMedia::getImageUrl($image, '600x400') !!} 600w,
             {!! ThumbnailMedia::getImageUrl($image, '900x600') !!} 900w"
     sizes="(max-width: 600px) 300px, (max-width: 900px) 600px, 900px">
```

### Trong Controller

```php
class ArticleController extends Controller
{
    public function show($id)
    {
        $article = Article::findOrFail($id);
        $thumbnailUrl = ThumbnailMedia::getImageUrl($article->image, '400x300');
        
        return view('article.show', compact('article', 'thumbnailUrl'));
    }
}
```

## ⚙️ Tùy chỉnh

Chỉnh sửa trong `PublicController.php`:

```php
// Cache duration
Cache::put($cacheKey, $imageData, now()->addDays(30)); // Mặc định: 30 ngày

// WebP quality
$encoder = new WebpEncoder(quality: 85); // Mặc định: 85

// Max width
if($size[0] > 1800) { $size[0] = 1800; } // Mặc định: 1800px
```

## 🔧 Troubleshooting

| Vấn đề | Giải pháp |
|--------|-----------|
| File not found | Kiểm tra: `php artisan storage:link` |
| WebP không hoạt động | Đảm bảo file `.webp` cùng tên, cùng thư mục với file gốc |
| Route not found | Kiểm tra service provider đã được load |

## 📌 Lưu ý

- File ảnh phải nằm trong `public` hoặc có symbolic link
- WebP chỉ áp dụng cho `jpg`, `jpeg`, `png`
- Cache tự động clear khi file thay đổi

---

**Version:** 1.0.0 | **Contact:** toan@visualweber.com

