<?php

call_user_func(function () {
        Route::pattern('blog_username', '[a-zA-Z0-9_\-]{1,20}');

        $prefix = '/blogof/{blog_username}';

        Route::group([
                        'domain' => UOJConfig::$data['web']['main']['host'],
                        'protocol' => UOJConfig::$data['web']['main']['protocol'],
                        'onload' => function() {
                                UOJContext::setupBlog();
                        }
                ], function() use ($prefix) {
                        Route::any("$prefix/", '/subdomain/blog/index.php');
                        Route::any("$prefix/archive", '/subdomain/blog/archive.php');
                        Route::any("$prefix/aboutme", '/subdomain/blog/aboutme.php');
                        Route::any("$prefix/click-zan", '/click_zan.php');
                        Route::any("$prefix/blog/{id}", '/subdomain/blog/blog.php');
                        Route::any("$prefix/slide/{id}", '/subdomain/blog/slide.php');
                        Route::any("$prefix/blog/(?:{id}|new)/write", '/subdomain/blog/blog_write.php?type=B');
                        Route::any("$prefix/slide/(?:{id}|new)/write", '/subdomain/blog/blog_write.php?type=S');
                        Route::any("$prefix/blog/{id}/delete", '/subdomain/blog/blog_delete.php');
                        Route::any("$prefix/blog/{id}/content.md", '/download.php?type=blog-md');
                        Route::any("$prefix/slide/{id}/content.yaml", '/download.php?type=slide-yaml');
                }
        );
}); 
