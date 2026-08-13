SET NAMES utf8mb4;
CREATE TABLE `site_options` (`option_id` bigint unsigned NOT NULL,`option_name` varchar(191),`option_value` longtext,`autoload` varchar(20),PRIMARY KEY (`option_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_options` VALUES (1,'siteurl','https://www.incoming.test','yes'),(2,'home','https://www.incoming.test','yes');
CREATE TABLE `site_posts` (
  `ID` bigint unsigned NOT NULL,`post_author` bigint unsigned NOT NULL DEFAULT 0,`post_date` datetime NOT NULL,`post_date_gmt` datetime NOT NULL,
  `post_content` longtext NOT NULL,`post_title` text NOT NULL,`post_excerpt` text NOT NULL,`post_status` varchar(20) NOT NULL,
  `comment_status` varchar(20) NOT NULL,`ping_status` varchar(20) NOT NULL,`post_password` varchar(255) NOT NULL,`post_name` varchar(200) NOT NULL,
  `to_ping` text NOT NULL,`pinged` text NOT NULL,`post_modified` datetime NOT NULL,`post_modified_gmt` datetime NOT NULL,
  `post_content_filtered` longtext NOT NULL,`post_parent` bigint unsigned NOT NULL DEFAULT 0,`guid` varchar(255) NOT NULL,`menu_order` int NOT NULL DEFAULT 0,
  `post_type` varchar(20) NOT NULL,`post_mime_type` varchar(100) NOT NULL,`comment_count` bigint NOT NULL DEFAULT 0,PRIMARY KEY (`ID`)
) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_posts` VALUES
(1,1,'2026-03-01 00:00:00','2026-03-01 00:00:00','Brand new with https://www.incoming.test/image.jpg and http://incoming.test/legacy','New article','','publish','open','open','','new-article','','','2026-03-01 00:00:00','2026-03-01 00:00:00','',0,'https://incoming.test/?p=1',0,'post','',0),
(15,1,'2026-02-01 00:00:00','2026-02-01 00:00:00','','photo.jpg','','inherit','open','closed','','photo-jpg','','','2026-02-01 00:00:00','2026-02-01 00:00:00','',1,'https://incoming.test/uploads/photo.jpg',0,'attachment','image/jpeg',0),
(99,1,'2026-01-01 00:00:00','2026-01-01 00:00:00','New body','Hello updated','new excerpt','publish','open','open','','hello','','','2026-02-02 00:00:00','2026-02-02 00:00:00','',0,'https://incoming.test/?p=99',0,'post','',0),
(200,1,'2025-01-01 00:00:00','2025-01-01 00:00:00','a:1:{s:4:"type";s:7:"gallery";}','Gallery','gallery','publish','closed','closed','','field_gallery','','','2025-01-01 00:00:00','2025-01-01 00:00:00','',0,'',0,'acf-field','',0);
CREATE TABLE `site_postmeta` (`meta_id` bigint unsigned NOT NULL,`post_id` bigint unsigned NOT NULL,`meta_key` varchar(255),`meta_value` longtext,PRIMARY KEY (`meta_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_postmeta` VALUES (1,1,'gallery','a:1:{i:0;i:15;}'),(2,99,'color','red'),(3,1,'_gallery','field_gallery'),(4,1,'source_url','a:1:{s:3:"url";s:34:"https://www.incoming.test/download";}');
CREATE TABLE `site_terms` (`term_id` bigint unsigned NOT NULL,`name` varchar(200),`slug` varchar(200),`term_group` bigint NOT NULL DEFAULT 0,PRIMARY KEY (`term_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_terms` VALUES (8,'Updates','updates',0);
CREATE TABLE `site_term_taxonomy` (`term_taxonomy_id` bigint unsigned NOT NULL,`term_id` bigint unsigned NOT NULL,`taxonomy` varchar(32),`description` longtext,`parent` bigint unsigned NOT NULL DEFAULT 0,`count` bigint NOT NULL DEFAULT 0,PRIMARY KEY (`term_taxonomy_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_term_taxonomy` VALUES (8,8,'category','',0,1);
CREATE TABLE `site_term_relationships` (`object_id` bigint unsigned NOT NULL,`term_taxonomy_id` bigint unsigned NOT NULL,`term_order` int NOT NULL DEFAULT 0,PRIMARY KEY (`object_id`,`term_taxonomy_id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_term_relationships` VALUES (1,8,0),(99,8,0);
CREATE TABLE `site_yoast_indexable` (`id` int unsigned NOT NULL,`object_id` bigint unsigned,`object_type` varchar(32),`title` text,PRIMARY KEY (`id`)) DEFAULT CHARSET=utf8mb4;
INSERT INTO `site_yoast_indexable` VALUES (10,1,'post','SEO https://www.incoming.test/new'),(11,99,'post','SEO Hello');
