<?php

declare(strict_types=1);

use app\theme\rendering\SafeThemeRenderer;
use app\theme\rendering\ViewContract;

$renderer = new SafeThemeRenderer();
$contract = new ViewContract(['title', 'body']);
$html = $renderer->render('<h1>{{ title }}</h1><p>{{body}}</p>', $contract, ['title' => '<script>x</script>', 'body' => 'Hello & bye']);
expectSame('<h1>&lt;script&gt;x&lt;/script&gt;</h1><p>Hello &amp; bye</p>', $html, 'renderer substitutes only escaped declared scalars');
expectThrows(fn () => $renderer->render('{{ title }}', $contract, ['title' => 'x']), InvalidArgumentException::class, 'all ViewContract fields are required in the view model');
expectThrows(fn () => $renderer->render('{{ missing }}', $contract, ['title' => 'x', 'body' => 'y']), InvalidArgumentException::class, 'undeclared placeholder is rejected');
expectThrows(fn () => $renderer->render('{php echo 1;} {{ title }}', $contract, ['title' => 'x', 'body' => 'y']), InvalidArgumentException::class, 'legacy server-side php directive is rejected');
expectThrows(fn () => $renderer->render('{if $x}yes{/if}', $contract, ['title' => 'x', 'body' => 'y']), InvalidArgumentException::class, 'unsupported legacy control directives are rejected rather than leaked as text');
expectThrows(fn () => $renderer->render('{hook func="x"}', $contract, ['title' => 'x', 'body' => 'y']), InvalidArgumentException::class, 'legacy hook directive is rejected');
expectThrows(fn () => $renderer->render('{{ title }}', $contract, ['title' => ['not scalar'], 'body' => 'y']), InvalidArgumentException::class, 'view values must be scalar/null');
