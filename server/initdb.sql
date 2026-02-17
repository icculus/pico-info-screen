create table if not exists zipcode_cache (
    id integer primary key,
    zipcode text not null unique,
    name text not null,
    country text not null,
    latitude real not null,
    longitude real not null,
    current_description text not null default '',
    current_icon text not null default '',
    current_temp real not null default '',
    current_feelslike real not null default 0.0,
    last_updated integer unsigned not null default 0
);

create index if not exists zipcode_index on zipcode_cache (zipcode);
