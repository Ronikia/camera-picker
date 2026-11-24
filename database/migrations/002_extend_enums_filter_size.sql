-- 002: расширение допустимых значений по реальным данным импорта.
--  - видео: hd (720p у старых камер), 5k и 7k (Panasonic 5.7K, Canon 7K)
--  - дальномерные камеры и байонеты (Leica M): rangefinder
--  - диаметр фильтра бывает дробным (40.5 мм) -> NUMERIC(4,1)

ALTER TABLE cameras DROP CONSTRAINT cameras_video_max_resolution_check;
ALTER TABLE cameras ADD CONSTRAINT cameras_video_max_resolution_check
    CHECK (video_max_resolution IN ('hd', 'fhd', '4k', '5k', '6k', '7k', '8k'));

ALTER TABLE cameras DROP CONSTRAINT cameras_body_type_check;
ALTER TABLE cameras ADD CONSTRAINT cameras_body_type_check
    CHECK (body_type IN ('mirrorless', 'dslr', 'rangefinder', 'compact'));

ALTER TABLE mounts DROP CONSTRAINT mounts_system_type_check;
ALTER TABLE mounts ADD CONSTRAINT mounts_system_type_check
    CHECK (system_type IN ('mirrorless', 'dslr', 'rangefinder'));

-- CHECK (filter_mm > 0) сохраняется при смене типа
ALTER TABLE lenses ALTER COLUMN filter_mm TYPE NUMERIC(4,1);
