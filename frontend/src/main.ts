import { createApp } from "vue";
import { createPinia } from "pinia";
import App from "./App.vue";
import { router } from "./router";
import { useAuth } from "./stores/auth";
import { onSessionLost } from "./lib/http";
import "./styles.css";

const app = createApp(App);
app.use(createPinia());
onSessionLost(() => {
  const auth = useAuth();
  const wasAuthenticated = Boolean(auth.user);
  auth.clear();
  if (wasAuthenticated && router.currentRoute.value.name !== "login") {
    void router.replace({
      name: "login",
      query: {
        reason: "expired",
        redirect: router.currentRoute.value.fullPath,
      },
    });
  }
});
app.use(router);
void router.isReady().then(() => app.mount("#app"));
