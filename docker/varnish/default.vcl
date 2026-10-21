vcl 4.1;

# Symfony app (the "origin")
backend default {
    .host = "app";
    .port = "80";
}

# Week 3: full purge (strategy A) goes here.
# Week 4: tag-based purge with xkey (strategy B) goes here.

sub vcl_deliver {
    # Make cache behaviour visible in response headers
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
