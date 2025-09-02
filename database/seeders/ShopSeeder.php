<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\ShopBrand;
use App\Models\ShopTag;
use App\Models\ShopProperty;
use App\Models\ShopWarehouse;
use App\Models\ShopPriceType;
use App\Models\ShopVariationAttribute;
use App\Models\ShopVariationAttributeValue;
use App\Models\ShopGood;
use App\Models\ShopGoodVariation;
use App\Models\ShopGoodImage;
use App\Models\ShopGoodVideo;
use App\Models\ShopStock;
use App\Models\ShopGoodPrice;
use App\Models\ShopCategory;

class ShopSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->seedBrands();
        $this->seedTags();
        $this->seedProperties();
        $this->seedWarehouses();
        $this->seedPriceTypes();
        $this->seedVariationAttributes();
        $this->seedGoods();
    }

    private function seedBrands(): void
    {
        $brands = [
            [
                'name' => 'Nike',
                'slug' => 'nike',
                'description' => 'Американская компания, специализирующаяся на спортивной одежде и обуви',
                'is_active' => true,
            ],
            [
                'name' => 'Adidas',
                'slug' => 'adidas',
                'description' => 'Немецкая компания, производитель спортивной одежды и обуви',
                'is_active' => true,
            ],
            [
                'name' => 'Puma',
                'slug' => 'puma',
                'description' => 'Немецкая компания, производитель спортивной одежды и обуви',
                'is_active' => true,
            ],
            [
                'name' => 'Vans',
                'slug' => 'vans',
                'description' => 'Американская компания, специализирующаяся на обуви для скейтбординга',
                'is_active' => true,
            ],
            [
                'name' => 'Converse',
                'slug' => 'converse',
                'description' => 'Американская компания, производитель обуви',
                'is_active' => true,
            ],
        ];

        foreach ($brands as $brand) {
            ShopBrand::firstOrCreate(['slug' => $brand['slug']], $brand);
        }
    }

    private function seedTags(): void
    {
        $tags = [
            [
                'name' => 'Новинка',
                'slug' => 'novinka',
                'color' => '#10B981',
                'is_active' => true,
            ],
            [
                'name' => 'Хит продаж',
                'slug' => 'hit-prodazh',
                'color' => '#EF4444',
                'is_active' => true,
            ],
            [
                'name' => 'Скидка',
                'slug' => 'skidka',
                'color' => '#F59E0B',
                'is_active' => true,
            ],
            [
                'name' => 'Ограниченная серия',
                'slug' => 'ogranichennaya-seriya',
                'color' => '#8B5CF6',
                'is_active' => true,
            ],
            [
                'name' => 'Эксклюзив',
                'slug' => 'eksklyuziv',
                'color' => '#EC4899',
                'is_active' => true,
            ],
        ];

        foreach ($tags as $tag) {
            ShopTag::firstOrCreate(['slug' => $tag['slug']], $tag);
        }
    }

    private function seedProperties(): void
    {
        $properties = [
            [
                'name' => 'Материал',
                'is_filterable' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Цвет',
                'is_filterable' => true,
                'sort_order' => 2,
            ],
            [
                'name' => 'Размер',
                'is_filterable' => true,
                'sort_order' => 3,
            ],
            [
                'name' => 'Вес',
                'is_filterable' => false,
                'sort_order' => 4,
            ],
            [
                'name' => 'Страна производитель',
                'is_filterable' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Гарантия',
                'is_filterable' => false,
                'sort_order' => 6,
            ],
        ];

        foreach ($properties as $property) {
            ShopProperty::firstOrCreate(['name' => $property['name']], $property);
        }
    }

    private function seedWarehouses(): void
    {
        $warehouses = [
            [
                'name' => 'Основной склад',
                'address' => 'г. Москва, ул. Складская, д. 1',
                'description' => 'Главный склад компании',
                'is_active' => true,
            ],
            [
                'name' => 'Склад в СПб',
                'address' => 'г. Санкт-Петербург, ул. Складская, д. 2',
                'description' => 'Региональный склад в Санкт-Петербурге',
                'is_active' => true,
            ],
            [
                'name' => 'Склад в Екатеринбурге',
                'address' => 'г. Екатеринбург, ул. Складская, д. 3',
                'description' => 'Региональный склад в Екатеринбурге',
                'is_active' => true,
            ],
        ];

        foreach ($warehouses as $warehouse) {
            ShopWarehouse::firstOrCreate(['name' => $warehouse['name']], $warehouse);
        }
    }

    private function seedPriceTypes(): void
    {
        $priceTypes = [
            [
                'name' => 'Розничная цена',
                'description' => 'Стандартная розничная цена',
                'multiplier' => 1.0,
                'is_active' => true,
                'is_default' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Оптовая цена',
                'description' => 'Цена для оптовых покупателей',
                'multiplier' => 0.8,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'VIP цена',
                'description' => 'Специальная цена для VIP клиентов',
                'multiplier' => 0.9,
                'is_active' => true,
                'is_default' => false,
                'sort_order' => 3,
            ],
        ];

        foreach ($priceTypes as $priceType) {
            ShopPriceType::firstOrCreate(['name' => $priceType['name']], $priceType);
        }
    }

    private function seedVariationAttributes(): void
    {
        // Размеры
        $sizeAttribute = ShopVariationAttribute::firstOrCreate(
            ['name' => 'Размер'],
            [
                'name' => 'Размер',
                'type' => 'select',
            ]
        );

        $sizes = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];
        foreach ($sizes as $size) {
            ShopVariationAttributeValue::firstOrCreate([
                'attribute_id' => $sizeAttribute->id,
                'value' => $size,
            ], [
                'attribute_id' => $sizeAttribute->id,
                'value' => $size,
            ]);
        }

        // Цвета
        $colorAttribute = ShopVariationAttribute::firstOrCreate(
            ['name' => 'Цвет'],
            [
                'name' => 'Цвет',
                'type' => 'select',
            ]
        );

        $colors = ['Черный', 'Белый', 'Красный', 'Синий', 'Зеленый', 'Желтый'];
        foreach ($colors as $color) {
            ShopVariationAttributeValue::firstOrCreate([
                'attribute_id' => $colorAttribute->id,
                'value' => $color,
            ], [
                'attribute_id' => $colorAttribute->id,
                'value' => $color,
            ]);
        }

        // Размеры обуви
        $shoeSizeAttribute = ShopVariationAttribute::firstOrCreate(
            ['name' => 'Размер обуви'],
            [
                'name' => 'Размер обуви',
                'type' => 'select',
            ]
        );

        $shoeSizes = ['36', '37', '38', '39', '40', '41', '42', '43', '44', '45'];
        foreach ($shoeSizes as $size) {
            ShopVariationAttributeValue::firstOrCreate([
                'attribute_id' => $shoeSizeAttribute->id,
                'value' => $size,
            ], [
                'attribute_id' => $shoeSizeAttribute->id,
                'value' => $size,
            ]);
        }
    }

    private function seedGoods(): void
    {
        // Получаем первые категории для привязки (если они есть)
        $categories = collect();
        try {
            $categories = ShopCategory::take(3)->get();
        } catch (\Exception $e) {
            // Категории не найдены, продолжаем без них
        }
        
        $brands = ShopBrand::take(3)->get();
        $tags = ShopTag::take(3)->get();
        $properties = ShopProperty::take(3)->get();
        $warehouses = ShopWarehouse::all();
        $priceTypes = ShopPriceType::all();

        $goods = [
            [
                'name' => 'Кроссовки Nike Air Max 270',
                'slug' => 'krossovki-nike-air-max-270',
                'sku' => 'NIKE-AM270-001',
                'description' => 'Современные кроссовки Nike Air Max 270 с максимальной амортизацией и стильным дизайном. Идеально подходят для повседневной носки и легких тренировок.',
                'short_description' => 'Кроссовки Nike Air Max 270 с максимальной амортизацией',
                'price' => 12990.00,
                'sale_price' => 9990.00,
                'stock_quantity' => 50,
                'width' => 30.0,
                'height' => 12.0,
                'depth' => 25.0,
                'rating' => 4.5,
                'reviews_count' => 128,
                'meta_title' => 'Кроссовки Nike Air Max 270 - купить в интернет-магазине',
                'meta_description' => 'Купить кроссовки Nike Air Max 270 по выгодной цене. Доставка по всей России. Гарантия качества.',
                'is_active' => true,
                'is_featured' => true,
                'is_new' => false,
                'has_discount' => true,
                'sort_order' => 1,
            ],
            [
                'name' => 'Куртка Adidas Originals',
                'slug' => 'kurtka-adidas-originals',
                'sku' => 'ADIDAS-ORIG-002',
                'description' => 'Классическая куртка Adidas Originals из качественных материалов. Удобный крой и стильный дизайн делают её идеальной для повседневной носки.',
                'short_description' => 'Классическая куртка Adidas Originals',
                'price' => 7990.00,
                'sale_price' => null,
                'stock_quantity' => 25,
                'width' => 60.0,
                'height' => 80.0,
                'depth' => 5.0,
                'rating' => 4.2,
                'reviews_count' => 67,
                'meta_title' => 'Куртка Adidas Originals - купить в интернет-магазине',
                'meta_description' => 'Купить куртку Adidas Originals по выгодной цене. Доставка по всей России.',
                'is_active' => true,
                'is_featured' => false,
                'is_new' => true,
                'has_discount' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Кеды Vans Old Skool',
                'slug' => 'kedi-vans-old-skool',
                'sku' => 'VANS-OS-003',
                'description' => 'Легендарные кеды Vans Old Skool - символ скейтбординга и уличной культуры. Классический дизайн и надежность на протяжении десятилетий.',
                'short_description' => 'Легендарные кеды Vans Old Skool',
                'price' => 5990.00,
                'sale_price' => 4990.00,
                'stock_quantity' => 75,
                'width' => 28.0,
                'height' => 10.0,
                'depth' => 22.0,
                'rating' => 4.7,
                'reviews_count' => 203,
                'meta_title' => 'Кеды Vans Old Skool - купить в интернет-магазине',
                'meta_description' => 'Купить кеды Vans Old Skool по выгодной цене. Классика скейтбординга.',
                'is_active' => true,
                'is_featured' => true,
                'is_new' => false,
                'has_discount' => true,
                'sort_order' => 3,
            ],
        ];

        foreach ($goods as $index => $goodData) {
            $good = ShopGood::create($goodData);

            // Привязываем категории
            if ($categories->count() > 0) {
                $good->categories()->attach($categories->random(rand(1, 2))->pluck('id'));
            }

            // Привязываем бренды
            if ($brands->count() > 0) {
                $good->brands()->attach($brands->random()->id);
            }

            // Привязываем теги
            if ($tags->count() > 0) {
                $good->tags()->attach($tags->random(rand(1, 2))->pluck('id'));
            }

            // Добавляем свойства
            if ($properties->count() > 0) {
                $selectedProperties = $properties->random(rand(2, 3));
                foreach ($selectedProperties as $property) {
                    $good->properties()->attach($property->id, [
                        'value' => $this->getPropertyValue($property->name)
                    ]);
                }
            }

            // Создаем остатки на складах
            foreach ($warehouses as $warehouse) {
                ShopStock::create([
                    'good_id' => $good->id,
                    'warehouse_id' => $warehouse->id,
                    'quantity' => rand(10, 100),
                ]);
            }

            // Создаем цены разных типов
            foreach ($priceTypes as $priceType) {
                $basePrice = $good->sale_price ?? $good->price;
                
                ShopGoodPrice::create([
                    'good_id' => $good->id,
                    'price_type_id' => $priceType->id,
                    'price' => $basePrice * $priceType->multiplier,
                ]);
            }

            // Создаем вариации для некоторых товаров
            if ($index < 2) {
                $this->createVariations($good);
            }
        }
    }

    private function getPropertyValue(string $propertyName): string
    {
        $values = [
            'Материал' => ['Хлопок', 'Полиэстер', 'Кожа', 'Деним', 'Шерсть'],
            'Цвет' => ['Черный', 'Белый', 'Красный', 'Синий', 'Зеленый'],
            'Размер' => ['XS', 'S', 'M', 'L', 'XL'],
            'Вес' => ['200г', '300г', '500г', '800г', '1кг'],
            'Страна производитель' => ['Китай', 'Вьетнам', 'Индонезия', 'Турция', 'Россия'],
            'Гарантия' => ['6 месяцев', '1 год', '2 года', '3 года'],
        ];

        $propertyValues = $values[$propertyName] ?? ['Не указано'];
        return $propertyValues[array_rand($propertyValues)];
    }

    private function createVariations(ShopGood $good): void
    {
        $sizeAttribute = ShopVariationAttribute::where('name', 'Размер')->first();
        $colorAttribute = ShopVariationAttribute::where('name', 'Цвет')->first();

        if (!$sizeAttribute || !$colorAttribute) {
            return;
        }

        $sizes = $sizeAttribute->values()->take(3)->get();
        $colors = $colorAttribute->values()->take(2)->get();

        foreach ($sizes as $size) {
            foreach ($colors as $color) {
                $variation = ShopGoodVariation::create([
                    'good_id' => $good->id,
                    'name' => $good->name . ' ' . $size->value . ' ' . $color->value,
                    'description' => $good->description . ' Размер: ' . $size->value . ', Цвет: ' . $color->value,
                    'short_description' => $good->short_description . ' ' . $size->value . ' ' . $color->value,
                    'price' => $good->price + rand(-500, 500),
                    'sale_price' => $good->sale_price ? $good->sale_price + rand(-300, 300) : null,
                    'stock_quantity' => rand(5, 30),
                    'width' => $good->width + rand(-2, 2),
                    'height' => $good->height + rand(-1, 1),
                    'depth' => $good->depth + rand(-1, 1),
                    'is_active' => true,
                    'sort_order' => rand(1, 10),
                ]);

                // Привязываем атрибуты вариации
                $variation->attributeValues()->attach([
                    $size->id,
                    $color->id,
                ]);

                // Создаем остатки для вариации
                $warehouses = ShopWarehouse::all();
                foreach ($warehouses as $warehouse) {
                    ShopStock::create([
                        'good_id' => $good->id,
                        'variation_id' => $variation->id,
                        'warehouse_id' => $warehouse->id,
                        'quantity' => rand(5, 25),
                    ]);
                }
            }
        }
    }
}
