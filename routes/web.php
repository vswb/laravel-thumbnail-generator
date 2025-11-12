<?php

use Illuminate\Support\Facades\Route;
/*
|--------------------------------------------------------------------------
| Hướng dẫn sử dụng package thumbnail-generator
|--------------------------------------------------------------------------
|
| 1. CÀI ĐẶT
|    - Copy package vào thư mục: dev-extensions/packages/thumbnail-generator
|    - Đảm bảo package đã được autoload trong composer.json
|    - Service provider tự động được đăng ký: Dev\ThumbnailGenerator\Providers\ThumbnailGeneratorServiceProvider
|
| 2. FACADE (ThumbnailMedia)
|    - Facade đã được tự động đăng ký và bind vào service container
|    - Không cần cấu hình thêm, có thể sử dụng ngay: ThumbnailMedia::methodName()
|
|    CÁCH SỬ DỤNG:
|
|    a) Trong BLADE TEMPLATE (không cần import):
|       {!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}
|       - Laravel tự động resolve facade, không cần use statement
|
|    b) Trong PHP CLASS (cần import facade):
|       use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade as ThumbnailMedia;
|       
|       // Hoặc sử dụng full namespace
|       \Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade::getImageUrl($url, $size);
|
|    c) Sử dụng với alias (nếu muốn đơn giản hóa):
|       // Trong config/app.php (aliases):
|       'ThumbnailMedia' => Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade::class,
|       
|       // Sau đó có thể dùng: ThumbnailMedia::method() mà không cần import
|
|
| 3. ROUTE RESIZE
|    - URL pattern: /resize/{slug}?w={width}&h={height}
|    - {slug}: đường dẫn file ảnh từ thư mục public (ví dụ: storage/news/image.jpg)
|    - Query parameters:
|      * w: chiều rộng (pixels, optional - tự động tính nếu không có)
|      * h: chiều cao (pixels, optional - tự động tính nếu không có)
|    - Hỗ trợ format: jpg, jpeg, png, webp, gif
|    - Tự động giới hạn max-width: 1800px
|
| 4. WEBP OPTIMIZATION (Tối ưu cho Google PageSpeed)
|    - Tự động ưu tiên file WebP nếu tồn tại
|    - Logic hoạt động:
|      a) Request: /resize/storage/news/9-375x250.jpg?w=300&h=100
|      b) Hệ thống check file WebP: storage/news/9-375x250.webp
|      c) Nếu TỒN TẠI → Dùng WebP → Encode WebP → Content-Type: image/webp
|      d) Nếu KHÔNG TỒN TẠI → Dùng file gốc (jpg/png) → Content-Type: image/jpeg hoặc image/png
|    - Chỉ áp dụng cho: jpg, jpeg, png (không check WebP cho file WebP hoặc GIF)
|    - Header được set đúng chuẩn WebP để tối ưu PageSpeed
|
| 5. CACHE
|    - Thumbnail được cache trong 30 ngày
|    - Cache key dựa trên: đường dẫn file, kích thước, format (webp/original), thời gian sửa đổi
|    - Cache riêng biệt cho WebP và format gốc
|    - Tự động invalidate khi file gốc thay đổi
|    - Cache-Control header: public, max-age=31536000, immutable (tối ưu PageSpeed)
|
| 6. SỬ DỤNG TRONG BLADE
|    
|    a) Sử dụng Facade (không cần import trong Blade):
|       {!! ThumbnailMedia::getImageUrl($fileUrl, '300x200') !!}
|       - $fileUrl: đường dẫn file ảnh (ví dụ: storage/news/image.jpg)
|       - '300x200': kích thước (width x height)
|       - Tự động thêm query params ?w=300&h=200 vào URL
|
|    b) Sử dụng URL trực tiếp:
|       <img src="{{ url('/resize/storage/news/image.jpg?w=300&h=200') }}" alt="Image">
|
|    c) Sử dụng trong HTML attribute:
|       <img src="{!! ThumbnailMedia::getImageUrl($fileUrl, '300xauto') !!}" alt="Image">
|       - '300xauto': tự động tính height dựa trên tỉ lệ gốc
|       - 'autox200': tự động tính width dựa trên tỉ lệ gốc
|
| 6.1. SỬ DỤNG TRONG PHP CLASS/CONTROLLER
|
|    // Import facade class
|    use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade as ThumbnailMedia;
|    
|    // Hoặc sử dụng full namespace
|    use Dev\ThumbnailGenerator\Facades\ThumbnailMediaFacade;
|    
|    class YourController extends Controller
|    {
|        public function index()
|        {
|            // Cách 1: Dùng với alias
|            $imageUrl = ThumbnailMedia::getImageUrl('storage/news/image.jpg', '300x200');
|            
|            // Cách 2: Dùng full namespace
|            $imageUrl = ThumbnailMediaFacade::getImageUrl('storage/news/image.jpg', '300x200');
|            
|            // Cách 3: Dùng dependency injection
|            $thumbnailMedia = app(Dev\ThumbnailGenerator\ThumbnailMedia::class);
|            $imageUrl = $thumbnailMedia->getImageUrl('storage/news/image.jpg', '300x200');
|            
|            return view('your.view', compact('imageUrl'));
|        }
|    }
|
| 7. VÍ DỤ SỬ DỤNG
|
|    // Ví dụ 1: Resize với kích thước cố định
|    {!! ThumbnailMedia::getImageUrl('storage/news/article.jpg', '300x200') !!}
|    // Output: /resize/storage/news/article.jpg?w=300&h=200
|
|    // Ví dụ 2: Resize với width cố định, height tự động
|    {!! ThumbnailMedia::getImageUrl('storage/news/article.jpg', '300xauto') !!}
|    // Output: /resize/storage/news/article.jpg?w=300
|
|    // Ví dụ 3: Sử dụng trong HTML
|    <img src="{!! ThumbnailMedia::getImageUrl($fileUrl, '500x300') !!}" 
|         alt="Thumbnail" 
|         loading="lazy">
|
|    // Ví dụ 4: WebP tự động (nếu có file .webp)
|    // Request: /resize/storage/news/image.jpg?w=300&h=200
|    // Nếu tồn tại: storage/news/image.webp
|    // → Hệ thống tự động dùng WebP và trả về Content-Type: image/webp
|
| 8. TÙY CHỈNH
|    - Mime types và max size: cấu hình qua AppMedia settings
|    - Cache duration: mặc định 30 ngày (có thể thay đổi trong PublicController)
|    - WebP quality: mặc định 85 (có thể thay đổi trong PublicController)
|
| 9. LƯU Ý
|    - File ảnh phải nằm trong thư mục public hoặc có symbolic link
|    - WebP optimization chỉ hoạt động khi có file .webp tương ứng trong cùng thư mục
|    - Cache sẽ tự động clear khi file gốc thay đổi (dựa trên filemtime)
|    - Hỗ trợ cả GET request với query parameters
|
*/

Route::group(['namespace' => 'Dev\ThumbnailGenerator\Http\Controllers'], function () {
    Route::get('resize/{slug}', [
        'uses' => 'PublicController@resize',
    ])->where('slug', 'storage/(.*).(jpg|jpeg|png|webp|gif)')
        ->name('thumbnail.resize');
});
