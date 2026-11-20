import { spawnSync } from "node:child_process";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const api =
  process.env.VITE_API_BASE_URL ||
  "https://api.billing.mfarosoftware.africa/api/v1";
const portal =
  process.env.VITE_PORTAL_URL || "https://billing.mfarosoftware.africa";
if (new URL(api).protocol !== "https:" || new URL(portal).protocol !== "https:")
  throw new Error("Production URLs must use HTTPS.");
for (const app of ["customer-portal", "admin-dashboard"]) {
  const result = spawnSync(
    process.execPath,
    [
      path.join(root, "node_modules/vite/bin/vite.js"),
      "build",
      "--configLoader",
      "native",
    ],
    {
      cwd: path.join(root, "apps", app),
      stdio: "inherit",
      env: { ...process.env, VITE_API_BASE_URL: api, VITE_PORTAL_URL: portal },
    },
  );
  if (result.status !== 0) process.exit(result.status || 1);
  const htaccess = path.join(root, "apps", app, "dist/.htaccess");
  let text = fs.readFileSync(htaccess, "utf8");
  text = text.replaceAll(
    "https://api.billing.mfarosoftware.africa",
    new URL(api).origin,
  );
  fs.writeFileSync(htaccess, text);
}
console.log("Production bundles built with API origin " + new URL(api).origin);
