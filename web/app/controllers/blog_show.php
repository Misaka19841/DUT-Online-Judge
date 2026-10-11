<?php
contest_security_mode_block_if_needed('blog', Auth::user());

UOJBlog::init(UOJRequest::get('id')) || UOJResponse::page404();

redirectTo(HTML::blog_url(UOJBlog::info('poster'), UOJContext::requestURI(), ['escape' => false]));
