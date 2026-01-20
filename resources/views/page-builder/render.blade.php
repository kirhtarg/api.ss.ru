<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $page->meta_title ?: $page->title }}</title>
    <meta name="description" content="{{ $page->meta_description }}">
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    @if($page->css_class)
        <style>
        .page-builder-container.{{ $page->css_class }} {
            /* Custom styles for page */
        }
        </style>
    @endif
</head>
<body class="page-builder-container {{ $page->css_class }}">
    @foreach($structure as $row)
        <div class="page-row" style="{{ $this->renderRowStyles($row) }}">
            <div class="flex" style="{{ $this->renderRowFlexStyles($row) }}">
                @foreach($row['columns'] as $column)
                    <div class="page-column" style="flex: {{ $column['width'] ? '0 0 ' . $column['width'] : '1' }}">
                        <div class="p-4">
                            @if(isset($column['blocks']) && is_array($column['blocks']))
                                @foreach($column['blocks'] as $block)
                                    {!! $this->renderBlock($block) !!}
                                @endforeach
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach

    <!-- Scripts for dynamic blocks -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    @if($this->hasSliderBlocks($structure))
        <script>
        // Slider initialization
        $(document).ready(function() {
            $('.page-slider').each(function() {
                // Initialize slider with settings
                const settings = $(this).data('settings');
                // Add slider initialization logic here
            });
        });
        </script>
    @endif
</body>
</html>