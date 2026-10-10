package jo.moshkelti.citizen;

import java.net.URI;
import java.net.URISyntaxException;
import java.util.Locale;

/** No WebView URL or report text can change the configured origin or enable administration. */
final class OriginPolicy {
    private final URI origin;

    OriginPolicy(String value) {
        origin = URI.create(value);
        if (!"https".equals(origin.getScheme()) || origin.getHost() == null
                || origin.getPort() != 8443 || origin.getRawUserInfo() != null
                || origin.getRawQuery() != null || origin.getRawFragment() != null
                || (origin.getRawPath() != null && !origin.getRawPath().isEmpty())) {
            throw new IllegalArgumentException("Invalid pilot origin");
        }
    }

    private URI parse(String value) {
        try { return new URI(value); } catch (URISyntaxException | NullPointerException ignored) { return null; }
    }

    boolean sameOrigin(String value) {
        URI uri = parse(value);
        return uri != null && "https".equals(uri.getScheme()) && uri.getRawUserInfo() == null
                && origin.getHost().equalsIgnoreCase(uri.getHost()) && uri.getPort() == origin.getPort();
    }

    boolean internal(String value) {
        URI uri = parse(value);
        if (!sameOrigin(value) || uri == null) { return false; }
        String raw = uri.getRawPath() == null ? "/" : uri.getRawPath().toLowerCase(Locale.ROOT);
        String path = uri.getPath() == null ? "/" : uri.getPath().toLowerCase(Locale.ROOT);
        if (raw.matches(".*%(2f|5c|2e|25).*")) { return false; }
        for (String part : path.split("/")) {
            if (part.equals(".") || part.equals("..")) { return false; }
        }
        return !path.equals("/admin") && !path.startsWith("/admin/") && !path.contains("\\");
    }

    boolean tile(String value) {
        URI uri = parse(value);
        return uri != null && "https".equals(uri.getScheme()) && "tile.openstreetmap.org".equals(uri.getHost())
                && uri.getRawUserInfo() == null && (uri.getPort() == -1 || uri.getPort() == 443)
                && uri.getRawQuery() == null && uri.getRawFragment() == null
                && uri.getPath().matches("/[0-9]{1,2}/[0-9]{1,8}/[0-9]{1,8}\\.png");
    }

    boolean external(String value) {
        URI uri = parse(value);
        if (uri == null || !"https".equals(uri.getScheme()) || uri.getRawUserInfo() != null
                || uri.getHost() == null || origin.getHost().equalsIgnoreCase(uri.getHost())
                || (uri.getPort() != -1 && uri.getPort() != 443)) { return false; }
        String host = uri.getHost().toLowerCase(Locale.ROOT);
        return host.matches("[a-z0-9-]+(?:\\.[a-z0-9-]+)*\\.[a-z]{2,}")
                && !host.endsWith(".localhost") && !host.endsWith(".local") && !host.endsWith(".internal");
    }
}
