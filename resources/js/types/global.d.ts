import { AxiosInstance } from 'axios';
import { route as ziggyRoute } from 'ziggy-js';

declare global {
    type Channel = 'email' | 'sms' | 'push' | 'interno' | 'alert_app';

    interface Window {
        axios: AxiosInstance;
    }

    var route: typeof ziggyRoute;
}
