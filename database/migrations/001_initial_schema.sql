-- 001: начальная схема каталога камер и объективов.
-- Расширение pg_trgm ставится суперпользователем при установке (см. README).

-- Автообновление updated_at при любом UPDATE
CREATE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
    NEW.updated_at := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------- brands
CREATE TABLE brands (
    id          INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug        VARCHAR(64)  NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    name        VARCHAR(128) NOT NULL,
    country     VARCHAR(64),
    website     TEXT,
    source_url  TEXT,
    verified    BOOLEAN      NOT NULL DEFAULT false,
    created_at  TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at  TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------- mounts
CREATE TABLE mounts (
    id                  INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug                VARCHAR(64)  NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    name                VARCHAR(128) NOT NULL,
    brand_id            INTEGER REFERENCES brands (id) ON DELETE SET NULL, -- NULL для открытых систем (L, M43)
    flange_distance_mm  NUMERIC(5,2) CHECK (flange_distance_mm > 0),
    system_type         VARCHAR(16)  NOT NULL CHECK (system_type IN ('mirrorless', 'dslr')),
    source_url          TEXT,
    verified            BOOLEAN      NOT NULL DEFAULT false,
    created_at          TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at          TIMESTAMPTZ  NOT NULL DEFAULT now()
);

-- ---------------------------------------------------------------- sensor_formats (справочник)
CREATE TABLE sensor_formats (
    code         VARCHAR(32)  PRIMARY KEY,
    name         VARCHAR(64)  NOT NULL,
    width_mm     NUMERIC(5,2) NOT NULL CHECK (width_mm > 0),
    height_mm    NUMERIC(5,2) NOT NULL CHECK (height_mm > 0),
    crop_factor  NUMERIC(4,2) NOT NULL CHECK (crop_factor > 0)
);

INSERT INTO sensor_formats (code, name, width_mm, height_mm, crop_factor) VALUES
    ('medium_format', 'Средний формат (44×33)', 43.80, 32.90, 0.79),
    ('full_frame',    'Полный кадр',            36.00, 24.00, 1.00),
    ('aps_c',         'APS-C',                  23.50, 15.60, 1.53),
    ('aps_c_canon',   'APS-C (Canon)',          22.30, 14.90, 1.61),
    ('m43',           'Micro Four Thirds',      17.30, 13.00, 2.00),
    ('one_inch',      '1"',                     13.20,  8.80, 2.73);

-- ---------------------------------------------------------------- cameras
CREATE TABLE cameras (
    id                    INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    brand_id              INTEGER      NOT NULL REFERENCES brands (id) ON DELETE RESTRICT,
    mount_id              INTEGER      REFERENCES mounts (id) ON DELETE RESTRICT, -- NULL у компактов
    slug                  VARCHAR(128) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    model                 VARCHAR(128) NOT NULL,
    body_type             VARCHAR(16)  NOT NULL CHECK (body_type IN ('mirrorless', 'dslr', 'compact')),
    sensor_format         VARCHAR(32)  NOT NULL REFERENCES sensor_formats (code),
    megapixels            NUMERIC(5,1) CHECK (megapixels > 0),
    ibis                  BOOLEAN,
    ibis_stops            NUMERIC(3,1) CHECK (ibis_stops >= 0),
    video_max_resolution  VARCHAR(8)   CHECK (video_max_resolution IN ('fhd', '4k', '6k', '8k')),
    video_max_fps         SMALLINT     CHECK (video_max_fps > 0),
    iso_max               INTEGER      CHECK (iso_max > 0),
    burst_fps             NUMERIC(5,1) CHECK (burst_fps > 0),
    weather_sealed        BOOLEAN,
    weight_g              INTEGER      CHECK (weight_g > 0),
    price_usd             INTEGER      CHECK (price_usd > 0),
    release_year          SMALLINT     CHECK (release_year BETWEEN 1990 AND 2100),
    description           TEXT,
    source_url            TEXT,
    verified              BOOLEAN      NOT NULL DEFAULT false,
    created_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at            TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (brand_id, slug),
    -- байонет обязателен для системных камер, у компактов его нет
    CHECK ((body_type = 'compact') = (mount_id IS NULL))
);

CREATE INDEX cameras_mount_id_idx      ON cameras (mount_id);
CREATE INDEX cameras_body_type_idx     ON cameras (body_type);
CREATE INDEX cameras_sensor_format_idx ON cameras (sensor_format);
CREATE INDEX cameras_model_trgm_idx    ON cameras USING gin (model gin_trgm_ops);

-- ---------------------------------------------------------------- lenses
CREATE TABLE lenses (
    id                   INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    brand_id             INTEGER      NOT NULL REFERENCES brands (id) ON DELETE RESTRICT,
    mount_id             INTEGER      NOT NULL REFERENCES mounts (id) ON DELETE RESTRICT,
    slug                 VARCHAR(128) NOT NULL CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    model                VARCHAR(160) NOT NULL,
    focal_min_mm         NUMERIC(6,1) NOT NULL CHECK (focal_min_mm > 0),
    focal_max_mm         NUMERIC(6,1) NOT NULL,
    aperture_wide        NUMERIC(3,1) NOT NULL CHECK (aperture_wide > 0),  -- f/число на широком конце
    aperture_tele        NUMERIC(3,1) NOT NULL,                            -- = aperture_wide у фиксов и постоянных зумов
    designed_for_format  VARCHAR(32)  NOT NULL REFERENCES sensor_formats (code),
    autofocus            BOOLEAN      NOT NULL DEFAULT true,
    stabilization        BOOLEAN,
    weather_sealed       BOOLEAN,
    min_focus_m          NUMERIC(5,3) CHECK (min_focus_m > 0),
    max_magnification    NUMERIC(4,2) CHECK (max_magnification > 0),      -- 1.00 = макро 1:1
    filter_mm            SMALLINT     CHECK (filter_mm > 0),
    weight_g             INTEGER      CHECK (weight_g > 0),
    price_usd            INTEGER      CHECK (price_usd > 0),
    release_year         SMALLINT     CHECK (release_year BETWEEN 1950 AND 2100),
    description          TEXT,
    source_url           TEXT,
    verified             BOOLEAN      NOT NULL DEFAULT false,
    created_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at           TIMESTAMPTZ  NOT NULL DEFAULT now(),
    UNIQUE (brand_id, mount_id, slug),
    CHECK (focal_max_mm >= focal_min_mm),
    CHECK (aperture_tele >= aperture_wide)
);

CREATE INDEX lenses_mount_id_idx    ON lenses (mount_id);
CREATE INDEX lenses_focal_idx       ON lenses (focal_min_mm, focal_max_mm);
CREATE INDEX lenses_format_idx      ON lenses (designed_for_format);
CREATE INDEX lenses_model_trgm_idx  ON lenses USING gin (model gin_trgm_ops);

-- ---------------------------------------------------------------- use_cases
CREATE TABLE use_cases (
    id           INTEGER GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    slug         VARCHAR(64)  NOT NULL UNIQUE CHECK (slug ~ '^[a-z0-9]+(-[a-z0-9]+)*$'),
    name         VARCHAR(128) NOT NULL,
    description  TEXT,
    sort_order   SMALLINT     NOT NULL DEFAULT 0,
    source_url   TEXT,
    verified     BOOLEAN      NOT NULL DEFAULT false,
    created_at   TIMESTAMPTZ  NOT NULL DEFAULT now(),
    updated_at   TIMESTAMPTZ  NOT NULL DEFAULT now()
);

INSERT INTO use_cases (slug, name, sort_order, verified) VALUES
    ('portrait',  'Портрет',          10, true),
    ('landscape', 'Пейзаж',           20, true),
    ('street',    'Улица / репортаж', 30, true),
    ('sport',     'Спорт',            40, true),
    ('wildlife',  'Дикая природа',    50, true),
    ('event',     'Мероприятия',      60, true),
    ('video',     'Видео',            70, true),
    ('travel',    'Путешествия',      80, true),
    ('macro',     'Макро',            90, true),
    ('astro',     'Астро',           100, true);

-- ---------------------------------------------------------------- связи со сценариями
CREATE TABLE camera_use_cases (
    camera_id    INTEGER  NOT NULL REFERENCES cameras (id)   ON DELETE CASCADE,
    use_case_id  INTEGER  NOT NULL REFERENCES use_cases (id) ON DELETE CASCADE,
    score        SMALLINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    PRIMARY KEY (camera_id, use_case_id)
);
CREATE INDEX camera_use_cases_use_case_idx ON camera_use_cases (use_case_id, score);

CREATE TABLE lens_use_cases (
    lens_id      INTEGER  NOT NULL REFERENCES lenses (id)    ON DELETE CASCADE,
    use_case_id  INTEGER  NOT NULL REFERENCES use_cases (id) ON DELETE CASCADE,
    score        SMALLINT NOT NULL CHECK (score BETWEEN 1 AND 5),
    PRIMARY KEY (lens_id, use_case_id)
);
CREATE INDEX lens_use_cases_use_case_idx ON lens_use_cases (use_case_id, score);

-- ---------------------------------------------------------------- триггеры updated_at
CREATE TRIGGER brands_updated_at    BEFORE UPDATE ON brands    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER mounts_updated_at    BEFORE UPDATE ON mounts    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER cameras_updated_at   BEFORE UPDATE ON cameras   FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER lenses_updated_at    BEFORE UPDATE ON lenses    FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER use_cases_updated_at BEFORE UPDATE ON use_cases FOR EACH ROW EXECUTE FUNCTION set_updated_at();
