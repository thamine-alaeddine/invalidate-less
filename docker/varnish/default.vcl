vcl 4.1;

# Symfony app (the "origin")
backend default {
    .host = "app";
    .port = "80";
}

# Who may send BAN requests: Docker networks only (local benchmark).
acl invalidators {
    "localhost";
    "10.0.0.0"/8;
    "172.16.0.0"/12;
    "192.168.0.0"/16;
}

sub vcl_recv {
    # Strategy A: FOSHttpCacheBundle sends BAN with X-Host, X-Url and X-Content-Type regexes.
    if (req.method == "BAN") {
        if (!client.ip ~ invalidators) {
            return (synth(405, "Not allowed"));
        }

        # Matches on object headers so the ban lurker can clean up in the background.
        ban("obj.http.X-Host ~ " + req.http.X-Host
            + " && obj.http.X-Url ~ " + req.http.X-Url
            + " && obj.http.Content-Type ~ " + req.http.X-Content-Type);

        return (synth(200, "Banned"));
    }
}

# Week 4: tag-based purge with xkey (strategy B) goes here.

sub vcl_backend_response {
    # Store URL and host on the object for lurker-friendly bans.
    set beresp.http.X-Url = bereq.url;
    set beresp.http.X-Host = bereq.http.host;
}

sub vcl_deliver {
    unset resp.http.X-Url;
    unset resp.http.X-Host;

    # Make cache behaviour visible in response headers
    if (obj.hits > 0) {
        set resp.http.X-Cache = "HIT";
    } else {
        set resp.http.X-Cache = "MISS";
    }
}
