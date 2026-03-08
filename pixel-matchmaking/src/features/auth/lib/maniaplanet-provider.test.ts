import { describe, it, expect } from "vitest";
import ManiaPlanet from "./maniaplanet-provider";
import type { ManiaPlanetProfile } from "./maniaplanet-provider";

describe("ManiaPlanet OAuth provider", () => {
  const provider = ManiaPlanet({
    clientId: "test_client_id",
    clientSecret: "test_client_secret",
  });

  it('has id "maniaplanet"', () => {
    expect(provider.id).toBe("maniaplanet");
  });

  it('has name "ManiaPlanet"', () => {
    expect(provider.name).toBe("ManiaPlanet");
  });

  it('has type "oauth"', () => {
    expect(provider.type).toBe("oauth");
  });

  it("has correct authorization URL", () => {
    const authConfig = provider.authorization as { url: string; params: Record<string, string> };
    expect(authConfig.url).toBe(
      "https://www.maniaplanet.com/login/oauth2/authorize",
    );
  });

  it("has scope=basic in authorization params", () => {
    const authConfig = provider.authorization as { url: string; params: Record<string, string> };
    expect(authConfig.params.scope).toBe("basic");
  });

  it("has correct token URL", () => {
    const tokenConfig = provider.token as { url: string };
    expect(tokenConfig.url).toBe(
      "https://www.maniaplanet.com/login/oauth2/access_token",
    );
  });

  it("has correct userinfo URL", () => {
    const userinfoConfig = provider.userinfo as { url: string };
    expect(userinfoConfig.url).toBe(
      "https://www.maniaplanet.com/webservices/me",
    );
  });

  it("uses provided clientId", () => {
    expect(provider.clientId).toBe("test_client_id");
  });

  it("uses provided clientSecret", () => {
    expect(provider.clientSecret).toBe("test_client_secret");
  });

  describe("profile() mapping", () => {
    const mockProfile: ManiaPlanetProfile = {
      login: "testplayer",
      nickname: "TestPlayer",
      path: "World|Europe|France",
    };

    it("maps login to id", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect(result.id).toBe("testplayer");
    });

    it("maps nickname to name", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect(result.name).toBe("TestPlayer");
    });

    it("generates a synthetic email from login", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect(result.email).toBe("testplayer@maniaplanet.local");
    });

    it("passes through login as a custom field", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect((result as Record<string, unknown>).login).toBe("testplayer");
    });

    it("passes through nickname as a custom field", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect((result as Record<string, unknown>).nickname).toBe("TestPlayer");
    });

    it("passes through path as a custom field", () => {
      const result = provider.profile!(mockProfile, {} as never);
      expect((result as Record<string, unknown>).path).toBe("World|Europe|France");
    });
  });
});
