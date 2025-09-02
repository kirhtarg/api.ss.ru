INSERT INTO `admin_pages` VALUES (1,'Магазин','shop','Магазин','Администрирование интернет-магазина','typcn:shopping-cart','Dashboard',1,1,'2025-08-30 18:43:13','2025-09-02 08:01:02');
INSERT INTO `admin_pages` VALUES (2,'Users','users','Пользователи','Управление пользователями системы','fas fa-users','Users',2,0,'2025-08-30 18:43:13','2025-08-31 06:00:56');
INSERT INTO `admin_pages` VALUES (3,'Settings','settings','Администратор','Настройки системы','clarity:administrator-line','Settings',0,1,'2025-08-30 18:43:13','2025-08-31 06:02:14');

INSERT INTO `users` VALUES (1,'Admin','admin@skateandsnow.ru','images/users/avatar_1_1756810643.png','2025-08-30 18:43:13','$2y$12$x6IWINE9FAyoXGp/sKUeBeA18fRXNCQWKeteWu1pGYCyA9opc6WdG',1,NULL,NULL,NULL,'2025-09-02 07:57:23');
INSERT INTO `users` VALUES (2,'Менеджер','manager@kirhtarg.ru',NULL,NULL,'$2y$12$GMPE5os5rPrPsj1tuyJNQODSnxIJgNxVVX8F3HPMx5VhxzIFIBC7.',1,NULL,NULL,'2025-09-02 08:50:17','2025-09-02 08:50:17');
INSERT INTO `users` VALUES (3,'Test','test@test.ru',NULL,NULL,'$2y$12$vY5TtxU8YP20vOfIyt0i6O2WEIU493ZvsQ2dx9piarNNQUTSlXbjy',1,NULL,NULL,'2025-09-02 08:55:51','2025-09-02 08:55:51');

INSERT INTO `roles` VALUES (1,'admin','Администратор','Полный доступ ко всем разделам админки',NULL,1,NULL,NULL);
INSERT INTO `roles` VALUES (2,'user','Пользователь','Зарегистрированый пользователь',NULL,1,NULL,NULL);
INSERT INTO `roles` VALUES (3,'manager','Менеджер','Доступ к администрированию магазина',NULL,1,NULL,NULL);

INSERT INTO `user_roles` VALUES (2,1,1,1,'2025-09-02 10:56:38',NULL,NULL);
INSERT INTO `user_roles` VALUES (4,3,3,1,'2025-09-02 11:55:51',NULL,NULL);
INSERT INTO `user_roles` VALUES (5,2,3,1,'2025-09-02 11:56:12',NULL,NULL);

INSERT INTO `admin_menu_items` VALUES (1,1,NULL,'carbon:ibm-watson-knowledge-catalog','Каталог товаров',NULL,'Администрирование товаров магазина',0,1,'2025-08-30 18:43:13','2025-09-02 08:14:45');
INSERT INTO `admin_menu_items` VALUES (2,2,NULL,'fas fa-users','Пользователи','/users','Управление пользователями системы',2,1,'2025-08-30 18:43:13','2025-08-30 18:43:13');
INSERT INTO `admin_menu_items` VALUES (3,3,NULL,'line-md:cog-loop','Настройки',NULL,'Редактирование значений переменных системы',0,1,'2025-08-30 18:43:13','2025-08-31 06:04:06');
INSERT INTO `admin_menu_items` VALUES (4,3,NULL,'flowbite:users-solid','Пользователи','users','Добавление и редактирование пользователей',1,1,'2025-08-30 18:52:41','2025-08-31 06:07:47');
INSERT INTO `admin_menu_items` VALUES (5,3,NULL,'fluent:braces-variable-24-filled','Переменные','variables','Редактирование переменных сайта',0,1,'2025-08-30 19:34:34','2025-08-31 06:05:43');
INSERT INTO `admin_menu_items` VALUES (6,3,NULL,'carbon:punctuation-check','Разделы админки','pages','Редактирование разделов админки',0,1,'2025-08-30 20:15:28','2025-08-31 06:06:23');
INSERT INTO `admin_menu_items` VALUES (7,1,NULL,'carbon:collapse-categories','Категории','categories','Редактирование категорий товаров',1,1,'2025-09-02 08:04:07','2025-09-02 08:14:45');
INSERT INTO `admin_menu_items` VALUES (8,3,NULL,'eos-icons:role-binding-outlined','Роли','roles','Администрирование ролей пользователей',0,1,'2025-09-02 08:52:08','2025-09-02 08:52:08');

INSERT INTO `settings` VALUES (1,'site_name','Название сайта','LLC SKATEandSNOW','string','general','Название сайта',NULL,NULL,'2025-08-30 18:43:14','2025-09-02 08:46:44');
INSERT INTO `settings` VALUES (2,'site_description','Описание сайта (description)','Магазин и аренда оборудования для сноуборда и скейтборда','text','general','Описание сайта',NULL,NULL,'2025-08-30 18:43:14','2025-08-30 18:43:14');
INSERT INTO `settings` VALUES (3,'site_logo','Логотип сайта','images\\settings\\setting_3_1756748468.png','image','general','Логотип в шапке сайта и везде, где необходимо',400,400,'2025-08-30 18:43:14','2025-08-31 06:16:53');
INSERT INTO `settings` VALUES (4,'main_site','URL адрес сайта','https://localhost:3001','string','general','Адрес основного сайта',200,200,'2025-08-30 18:43:14','2025-08-30 18:43:14');
INSERT INTO `settings` VALUES (5,'quick_login','Быстрый вход','1','boolean','general','Отключает кнопку быстрого входа под тестовым пользователем при аутентификации',200,200,'2025-08-30 18:43:14','2025-08-31 07:14:49');
INSERT INTO `settings` VALUES (6,'admin_name','Название админки','Skate&Snow CMS','string','general','Значение переменной - в заголовках админки',NULL,NULL,'2025-08-30 19:41:04','2025-08-31 04:43:30');
INSERT INTO `settings` VALUES (8,'site_favicon','Иконка сайта','images/settings/setting_8_1756812882.png','image','general','Иконка сайта, отображаемая на сайте и в админке',32,32,'2025-08-30 20:00:22','2025-09-02 08:34:42');
INSERT INTO `settings` VALUES (9,'shop_category_img_width','Ширина изображения в каталоге','350','number','shop','Задает ширину, к которой приводится изображение к каталоге товаров, px',NULL,NULL,'2025-09-02 08:36:15','2025-09-02 08:38:01');
INSERT INTO `settings` VALUES (10,'shop_category_img_height','Высота изображения в каталоге товаров','350','number','shop','Задает высоту, к которой приводится изображение к каталоге товаров, px',NULL,NULL,'2025-09-02 08:37:31','2025-09-02 08:38:01');

INSERT INTO `admin_page_role` VALUES (1,1,3,NULL,NULL);