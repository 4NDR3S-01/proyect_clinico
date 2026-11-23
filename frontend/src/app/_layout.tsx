import { Stack } from "expo-router";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import { useCallback, useState } from "react";
import { SplashOverlay } from "@/components/splash-overlay";
import { AuthProvider, useAuth } from "@/features/auth/auth-context";
import {
  initialWindowMetrics,
  SafeAreaProvider,
} from "react-native-safe-area-context";

// El splash nativo se queda hasta que SplashOverlay lo reemplaza.
SplashScreen.preventAutoHideAsync().catch(() => undefined);

function AppSplash() {
  const { status } = useAuth();
  const [visible, setVisible] = useState(true);
  const handleFinish = useCallback(() => setVisible(false), []);

  if (!visible) return null;
  return <SplashOverlay ready={status !== "restoring"} onFinish={handleFinish} />;
}

export default function RootLayout() {
  return (
    <SafeAreaProvider initialMetrics={initialWindowMetrics}>
      <AuthProvider>
        <StatusBar style="dark" />
        <Stack screenOptions={{ headerShown: false, animation: "fade" }} />
        <AppSplash />
      </AuthProvider>
    </SafeAreaProvider>
  );
}
