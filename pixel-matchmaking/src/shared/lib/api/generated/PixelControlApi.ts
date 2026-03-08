/* generated using openapi-typescript-codegen -- do not edit */
/* istanbul ignore file */
/* tslint:disable */
/* eslint-disable */
import type { BaseHttpRequest } from './core/BaseHttpRequest';
import type { OpenAPIConfig } from './core/OpenAPI';
import { FetchHttpRequest } from './core/FetchHttpRequest';
import { AdminAuthService } from './services/AdminAuthService';
import { AdminMapsService } from './services/AdminMapsService';
import { AdminMatchService } from './services/AdminMatchService';
import { AdminPlayersService } from './services/AdminPlayersService';
import { AdminTeamsService } from './services/AdminTeamsService';
import { AdminVotesService } from './services/AdminVotesService';
import { AdminWarmupPauseService } from './services/AdminWarmupPauseService';
import { AdminWhitelistService } from './services/AdminWhitelistService';
import { ConfigTemplatesService } from './services/ConfigTemplatesService';
import { HealthService } from './services/HealthService';
import { LifecycleService } from './services/LifecycleService';
import { LinkService } from './services/LinkService';
import { MapsService } from './services/MapsService';
import { ModeService } from './services/ModeService';
import { PlayersService } from './services/PlayersService';
import { PluginEventsService } from './services/PluginEventsService';
import { ServerConfigTemplateService } from './services/ServerConfigTemplateService';
import { ServerStateService } from './services/ServerStateService';
import { ServerStatusService } from './services/ServerStatusService';
import { StatsService } from './services/StatsService';
import { StatsEliteService } from './services/StatsEliteService';
import { VetoDraftService } from './services/VetoDraftService';
type HttpRequestConstructor = new (config: OpenAPIConfig) => BaseHttpRequest;
export class PixelControlApi {
    public readonly adminAuth: AdminAuthService;
    public readonly adminMaps: AdminMapsService;
    public readonly adminMatch: AdminMatchService;
    public readonly adminPlayers: AdminPlayersService;
    public readonly adminTeams: AdminTeamsService;
    public readonly adminVotes: AdminVotesService;
    public readonly adminWarmupPause: AdminWarmupPauseService;
    public readonly adminWhitelist: AdminWhitelistService;
    public readonly configTemplates: ConfigTemplatesService;
    public readonly health: HealthService;
    public readonly lifecycle: LifecycleService;
    public readonly link: LinkService;
    public readonly maps: MapsService;
    public readonly mode: ModeService;
    public readonly players: PlayersService;
    public readonly pluginEvents: PluginEventsService;
    public readonly serverConfigTemplate: ServerConfigTemplateService;
    public readonly serverState: ServerStateService;
    public readonly serverStatus: ServerStatusService;
    public readonly stats: StatsService;
    public readonly statsElite: StatsEliteService;
    public readonly vetoDraft: VetoDraftService;
    public readonly request: BaseHttpRequest;
    constructor(config?: Partial<OpenAPIConfig>, HttpRequest: HttpRequestConstructor = FetchHttpRequest) {
        this.request = new HttpRequest({
            BASE: config?.BASE ?? '',
            VERSION: config?.VERSION ?? '0.1.0',
            WITH_CREDENTIALS: config?.WITH_CREDENTIALS ?? false,
            CREDENTIALS: config?.CREDENTIALS ?? 'include',
            TOKEN: config?.TOKEN,
            USERNAME: config?.USERNAME,
            PASSWORD: config?.PASSWORD,
            HEADERS: config?.HEADERS,
            ENCODE_PATH: config?.ENCODE_PATH,
        });
        this.adminAuth = new AdminAuthService(this.request);
        this.adminMaps = new AdminMapsService(this.request);
        this.adminMatch = new AdminMatchService(this.request);
        this.adminPlayers = new AdminPlayersService(this.request);
        this.adminTeams = new AdminTeamsService(this.request);
        this.adminVotes = new AdminVotesService(this.request);
        this.adminWarmupPause = new AdminWarmupPauseService(this.request);
        this.adminWhitelist = new AdminWhitelistService(this.request);
        this.configTemplates = new ConfigTemplatesService(this.request);
        this.health = new HealthService(this.request);
        this.lifecycle = new LifecycleService(this.request);
        this.link = new LinkService(this.request);
        this.maps = new MapsService(this.request);
        this.mode = new ModeService(this.request);
        this.players = new PlayersService(this.request);
        this.pluginEvents = new PluginEventsService(this.request);
        this.serverConfigTemplate = new ServerConfigTemplateService(this.request);
        this.serverState = new ServerStateService(this.request);
        this.serverStatus = new ServerStatusService(this.request);
        this.stats = new StatsService(this.request);
        this.statsElite = new StatsEliteService(this.request);
        this.vetoDraft = new VetoDraftService(this.request);
    }
}

