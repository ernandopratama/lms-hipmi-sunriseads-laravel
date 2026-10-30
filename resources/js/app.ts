import "../css/app.css";
import { createApp } from "vue";
import App from "./App.vue";
import router from "./router";

createApp(App).use(router).mount("#app");
// Tells the preloader (resources/views/partials/preloader.blade.php) the first route has rendered.
router.isReady().then(() => dispatchEvent(new Event("app:mounted")));
