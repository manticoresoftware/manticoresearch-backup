-- table rt_with_columnar
CREATE TABLE rt_with_columnar (
  id BIGINT,
  title TEXT,
  category_id INTEGER,
  price FLOAT,
  description STRING engine='COLUMNAR',
  tags MULTI,
  attributes JSON
);

-- table rt_without_columnar
CREATE TABLE rt_without_columnar (
  id BIGINT,
  title TEXT,
  category_id INTEGER,
  price FLOAT,
  description STRING,
  tags MULTI,
  attributes JSON
);

CREATE TABLE test ( title text, image_vector float_vector knn_type='hnsw' knn_dims='4' hnsw_similarity='l2' );

-- table distributed_index
CREATE TABLE distributed_index type='distributed' local='rt_with_columnar, rt_without_columnar' AGENT='127.0.0.1:9312:plain_with_columnar, plain_without_columnar';

-- data for rt_with_columnar
INSERT INTO rt_with_columnar (id, title, category_id, price, description, tags, attributes) VALUES
(1, 'Manticore T-Shirt', 1, 19.99, 'Comfortable and stylish Manticore T-Shirt', (101, 102), '{"color": "black", "size": "M"}'),
(2, 'Manticore Hoodie', 1, 39.99, 'Warm and cozy Manticore Hoodie', (101, 103), '{"color": "gray", "size": "L"}'),
(3, 'Manticore Cap', 2, 14.99, 'Adjustable Manticore Cap', (102, 104), '{"color": "blue", "size": "one size"}'),
(4, 'Manticore Mug', 3, 9.99, 'Ceramic Manticore Mug', (103, 105), '{"capacity": "350ml", "color": "white"}'),
(5, 'Manticore Poster', 4, 4.99, 'High-quality Manticore Poster', (104, 106), '{"dimensions": "24x36 inches", "material": "glossy paper"}'),
(6, 'Manticore Stickers', 4, 2.99, 'Colorful Manticore Stickers', (105, 107), '{"quantity": "10", "material": "vinyl"}'),
(7, 'Manticore Notebook', 5, 12.99, 'Manticore lined notebook', (106, 108), '{"pages": "100", "cover": "hard"}'),
(8, 'Manticore Pen', 5, 1.99, 'Smooth-writing Manticore Pen', (107, 109), '{"ink_color": "blue", "type": "ballpoint"}'),
(9, 'Manticore Keychain', 6, 3.99, 'Durable Manticore Keychain', (108, 110), '{"material": "metal", "design": "round"}'),
(10, 'Manticore Water Bottle', 6, 24.99, 'Leak-proof Manticore Water Bottle', (109, 111), '{"capacity": "1L", "material": "stainless steel"}'),
(11, 'Manticore Backpack', 7, 49.99, 'Spacious Manticore Backpack', (201, 202), '{"color": "black", "capacity": "20L"}'),
(12, 'Manticore Phone Case', 8, 15.99, 'Protective Manticore Phone Case', (201, 203), '{"color": "clear", "model": "iPhone 13"}'),
(13, 'Manticore Socks', 1, 7.99, 'Comfy Manticore Socks', (202, 204), '{"color": "white", "size": "M"}');

-- data for rt_without_columnar
INSERT INTO rt_without_columnar (id, title, category_id, price, description, tags, attributes) VALUES
(1, 'Manticore T-Shirt', 1, 19.99, 'Comfortable and stylish Manticore T-Shirt', (101, 102), '{"color": "black", "size": "M"}'),
(2, 'Manticore Hoodie', 1, 39.99, 'Warm and cozy Manticore Hoodie', (101, 103), '{"color": "gray", "size": "L"}'),
(3, 'Manticore Cap', 2, 14.99, 'Adjustable Manticore Cap', (102, 104), '{"color": "blue", "size": "one size"}'),
(4, 'Manticore Mug', 3, 9.99, 'Ceramic Manticore Mug', (103, 105), '{"capacity": "350ml", "color": "white"}'),
(5, 'Manticore Poster', 4, 4.99, 'High-quality Manticore Poster', (104, 106), '{"dimensions": "24x36 inches", "material": "glossy paper"}'),
(6, 'Manticore Stickers', 4, 2.99, 'Colorful Manticore Stickers', (105, 107), '{"quantity": "10", "material": "vinyl"}'),
(7, 'Manticore Notebook', 5, 12.99, 'Manticore lined notebook', (106, 108), '{"pages": "100", "cover": "hard"}'),
(8, 'Manticore Pen', 5, 1.99, 'Smooth-writing Manticore Pen', (107, 109), '{"ink_color": "blue", "type": "ballpoint"}'),
(9, 'Manticore Keychain', 6, 3.99, 'Durable Manticore Keychain', (108, 110), '{"material": "metal", "design": "round"}'),
(10, 'Manticore Water Bottle', 6, 24.99, 'Leak-proof Manticore Water Bottle', (109, 111), '{"capacity": "1L", "material": "stainless steel"}'),
(11, 'Manticore Backpack', 7, 49.99, 'Spacious Manticore Backpack', (201, 202), '{"color": "black", "capacity": "20L"}'),
(12, 'Manticore Phone Case', 8, 15.99, 'Protective Manticore Phone Case', (201, 203), '{"color": "clear", "model": "iPhone 13"}'),
(13, 'Manticore Socks', 1, 7.99, 'Comfy Manticore Socks', (202, 204), '{"color": "white", "size": "M"}');

INSERT INTO test VALUES
( 1, 'yellow bag', (0.653448,0.192478,0.017971,0.339821) ),
( 2, 'white bag', (0.148894,0.748278,0.091892,0.095406) );
