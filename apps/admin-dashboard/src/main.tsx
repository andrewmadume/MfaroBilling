import React from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { QueryClient, QueryClientProvider } from "@tanstack/react-query";
import { App } from "../../../packages/ui/src/App";
import "../../../packages/ui/src/style.css";
createRoot(document.getElementById("root")!).render(
  <React.StrictMode>
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: {
            queries: { retry: 1, refetchOnWindowFocus: false },
          },
        })
      }
    >
      <BrowserRouter>
        <App admin={true} />
      </BrowserRouter>
    </QueryClientProvider>
  </React.StrictMode>,
);
