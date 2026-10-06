import fs from "node:fs";
import path from "node:path";
import sharp from "sharp";

const root = process.cwd();
const input = path.join(root, "assets", "home-beauty.png");
const output = path.join(root, "src", "home-photo.generated.js");

if (!fs.existsSync(input)) {
  console.error("HOME_PHOTO_BUILD_BLOCK=missing_source_asset");
  process.exit(21);
}

const source = fs.readFileSync(input);
const optimized = await sharp(source)
  .rotate()
  .resize({ width: 800, withoutEnlargement: true })
  .webp({ quality: 60, effort: 6 })
  .toBuffer();

if (optimized.length > 180000) {
  console.error(`HOME_PHOTO_BUILD_BLOCK=optimized_asset_too_large:${optimized.length}`);
  process.exit(22);
}

const b64 = optimized.toString("base64");
fs.writeFileSync(output, `export const HOME_PHOTO_B64 = '${b64}';\n`, "utf8");
console.log(`HOME_PHOTO_BUILD=GREEN bytes=${optimized.length}`);
