import test from "node:test";
import assert from "node:assert/strict";
import { formatBytes, safeEndpoint } from "../resources/js/dashboard.js";
test("dashboard actions stay on the application origin", () => {
  assert.equal(
    safeEndpoint("/drivesnap/status?page=2", "https://example.test"),
    "/drivesnap/status?page=2",
  );
  for (const value of [
    "https://evil.test/steal",
    "//evil.test/steal",
    "javascript:alert(1)",
    "https://user:secret@example.test/",
  ]) {
    assert.throws(() => safeEndpoint(value, "https://example.test"));
  }
});
test("archive sizes remain readable without accepting invalid values", () => {
  assert.equal(formatBytes(0), "0 B");
  assert.equal(formatBytes(1024), "1.0 KiB");
  assert.equal(formatBytes(1024 ** 3), "1.0 GiB");
  assert.equal(formatBytes(-1), "—");
});
