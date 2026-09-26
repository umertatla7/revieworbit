import { describe, expect, it, vi } from "vitest";
import Home from "./page";

const { redirectMock } = vi.hoisted(() => ({ redirectMock: vi.fn() }));
vi.mock("next/navigation", () => ({ redirect: redirectMock }));

describe("home", () => {
  it("redirects the application root to sign in", () => {
    Home();
    expect(redirectMock).toHaveBeenCalledWith("/login");
  });
});
