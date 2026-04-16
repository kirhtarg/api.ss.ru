<?php
$filePath = 'f:\Work\Projects\SS\api.ss.ru\app\Http\Controllers\Admin\BulkGoodsImportController.php';
$content = file_get_contents($filePath);

// Substitutions
$map = [
    "/'message'\s+=>\s+'Р˜РјРїРѕСЂС‚ Р·Р°РІРµСЂС€РµРЅ'/" => "'message' => 'Импорт завершен'",
    "/'message'\s+=>\s+'РћР±РЅСѓР»РµРЅРёРµ РѕСЃС‚Р°С‚РєРѕРІ РѕС‚РєР»СЋС‡РµРЅРѕ'/" => "'message' => 'Обнуление остатков отключено'",
    "/'reason'\s+=>\s+'Р¤СѓРЅРєС†РёСЏ РѕР±РЅСѓР»РµРЅРёСЏ РѕСЃС‚Р°С‚РєРѕРІ РїРѕР»РЅРѕСЃС‚СЊСЋ РѕС‚РєР»СЋС‡РµРЅР° РїРѕ С‚СЂРµР±РѕРІР°РЅРёСЋ'/" => "'reason' => 'Функция обнуления остатков полностью отключена по требованию'"
];

foreach ($map as $pattern => $to) {
    if (preg_match($pattern, $content)) {
        $content = preg_replace($pattern, $to, $content);
        echo "Replaced pattern: $pattern\n";
    } else {
        echo "Not found pattern: $pattern\n";
    }
}

file_put_contents($filePath, $content);
echo "Done.\n";
