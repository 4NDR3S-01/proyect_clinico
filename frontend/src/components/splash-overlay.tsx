import { Image } from "expo-image";
import * as SplashScreen from "expo-splash-screen";
import { StatusBar } from "expo-status-bar";
import { useEffect, useRef, useState } from "react";
import { Animated, Easing, StyleSheet, Text, View } from "react-native";

// Mismo color y tamaño que el splash nativo (app.json), para que el paso
// del splash nativo a esta pantalla no se note.
const BACKGROUND = "#102A43";
const SEAL_SIZE = 200;
const MIN_VISIBLE_MS = 2500;

type Props = {
  /** true cuando la app ya sabe a qué pantalla ir (sesión restaurada). */
  ready: boolean;
  /** Se llama al terminar de desvanecerse, para desmontar el overlay. */
  onFinish: () => void;
};

export function SplashOverlay({ ready, onFinish }: Props) {
  const opacity = useRef(new Animated.Value(1)).current;
  const sealScale = useRef(new Animated.Value(1)).current;
  const textOpacity = useRef(new Animated.Value(0)).current;
  const textOffset = useRef(new Animated.Value(12)).current;
  const [introDone, setIntroDone] = useState(false);

  // 1. Al aparecer: ocultar el splash nativo (este overlay se ve igual)
  //    y animar el sello y el texto.
  const handleLayout = () => {
    SplashScreen.hideAsync().catch(() => undefined);

    Animated.parallel([
      Animated.sequence([
        Animated.timing(sealScale, {
          toValue: 1.06,
          duration: 260,
          easing: Easing.out(Easing.quad),
          useNativeDriver: true,
        }),
        Animated.spring(sealScale, {
          toValue: 1,
          friction: 5,
          useNativeDriver: true,
        }),
      ]),
      Animated.timing(textOpacity, {
        toValue: 1,
        duration: 450,
        delay: 150,
        useNativeDriver: true,
      }),
      Animated.timing(textOffset, {
        toValue: 0,
        duration: 450,
        delay: 150,
        easing: Easing.out(Easing.cubic),
        useNativeDriver: true,
      }),
    ]).start();
  };

  // 2. Tiempo mínimo en pantalla, para que la animación se alcance a ver.
  useEffect(() => {
    const timer = setTimeout(() => setIntroDone(true), MIN_VISIBLE_MS);
    return () => clearTimeout(timer);
  }, []);

  // 3. Cuando la sesión está lista y pasó el tiempo mínimo: desvanecer.
  useEffect(() => {
    if (!ready || !introDone) return;
    Animated.timing(opacity, {
      toValue: 0,
      duration: 350,
      easing: Easing.in(Easing.quad),
      useNativeDriver: true,
    }).start(({ finished }) => {
      if (finished) onFinish();
    });
  }, [ready, introDone, opacity, onFinish]);

  return (
    <Animated.View
      onLayout={handleLayout}
      style={[
        styles.container,
        { opacity, pointerEvents: ready && introDone ? "none" : "auto" },
      ]}
      accessibilityLabel="Consultorio Odontológico, cargando"
    >
      <StatusBar style="light" />

      <Animated.View style={{ transform: [{ scale: sealScale }] }}>
        <Image
          source={require("../../assets/images/monograma-1024.png")}
          style={styles.seal}
          contentFit="contain"
          accessible={false}
        />
      </Animated.View>

      <Animated.View
        style={[
          styles.textBlock,
          { opacity: textOpacity, transform: [{ translateY: textOffset }] },
        ]}
      >
        <Text style={styles.title}>CONSULTORIO</Text>
        <View style={styles.divider} />
        <Text style={styles.subtitle}>ODONTOLÓGICO</Text>
      </Animated.View>

      <Animated.Text style={[styles.footer, { opacity: textOpacity }]}>
        Portal del Paciente
      </Animated.Text>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
    container: {
        ...StyleSheet.absoluteFill,
        backgroundColor: BACKGROUND,
        alignItems: "center",
        justifyContent: "center",
        zIndex: 1000,
        elevation: 1000,
    },
  // El sello queda exactamente en el centro, igual que en el splash nativo.
  seal: { width: SEAL_SIZE, height: SEAL_SIZE },
  textBlock: {
    position: "absolute",
    top: "50%",
    marginTop: SEAL_SIZE / 2 + 12,
    alignItems: "center",
    gap: 10,
  },
  title: {
    color: "#FFFFFF",
    fontSize: 26,
    fontWeight: "800",
    letterSpacing: 3.6,
  },
  divider: {
    width: 40,
    height: 3,
    borderRadius: 2,
    backgroundColor: "#0E9F8A",
  },
  subtitle: {
    color: "#D7E2EE",
    fontSize: 15,
    fontWeight: "700",
    letterSpacing: 4.8,
    paddingLeft: 4.8,
  },
  footer: {
    position: "absolute",
    bottom: 56,
    color: "#D7E2EE",
    fontSize: 13,
    fontWeight: "500",
  },
});