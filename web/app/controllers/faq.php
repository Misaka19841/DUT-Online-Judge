<?php echoUOJPageHeader(UOJLocale::get('help')) ?>
<article class="uoj-article">
	<header>
		<h2 class="page-header">常见问题及其解答</h2>
	</header>
	<section>
		<header>
			<h4>1. 如何撰写博客/题解</h4>
		</header>
		<p>功能基本和 Markdown 一样。</p>
		<p>特别的是，我们可以在文章中添加&lt!-- readmore --&gt，这样预览就会在该处结束。</p>
		<p>如果不这样做，预览会在几个字符后自动结束。</p
                <p>在 DOJ 中有一个搜索博客的功能，您可以搜索作者或标签，还有一个 “题解 ”按钮，可以搜索标签 “sol=123”（作为 123 号问题的题解）。</p>
                <p>如果一篇博客同时覆盖一段连续题号，也可以直接添加区段标签，例如 “sol=8~19”。此时搜索 “sol=10” 或 “sol=8~19” 都可以找到它。</p>
	</section>
	<section>
		<header>
			<h4>2. 我的头像是如何显示的/为什么我不能更改头像</h4>
		</header>
		<p>我们通过著名的 Gravatar 获取您的头像。我们建议您使用 qq 邮件注册，这样我们就能自动获取您的头像。</p>
		<p>如果您的头像有任何问题，可以向 Gravatar 寻求帮助或联系管理员。</p> 
	</section>
</article>

<?php echoUOJPageFooter() ?>
