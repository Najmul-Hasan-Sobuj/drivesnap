import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { parse, compileScript, compileTemplate } from "@vue/compiler-sfc";
import { parse as parseJsx } from "@babel/parser";
import { compile } from "svelte/compiler";
const stub = (extension) =>
  readFileSync(
    new URL("../resources/stubs/Dashboard." + extension, import.meta.url),
    "utf8",
  ).replace("__IMPORT__", "../../drivesnap/dashboard.js");
test("published Vue page compiles including its TypeScript props", () => {
  const source = stub("vue");
  const { descriptor, errors } = parse(source);
  assert.equal(errors.length, 0);
  assert.ok(compileScript(descriptor, { id: "drivesnap" }).content);
  assert.equal(
    compileTemplate({
      source: descriptor.template.content,
      filename: "Dashboard.vue",
      id: "drivesnap",
    }).errors.length,
    0,
  );
});
test("published React page is valid JSX", () => {
  assert.ok(parseJsx(stub("jsx"), { sourceType: "module", plugins: ["jsx"] }));
});
test("published Svelte page compiles for browser and SSR", () => {
  for (const generate of ["client", "server"])
    assert.ok(compile(stub("svelte"), { generate }).js.code);
});
